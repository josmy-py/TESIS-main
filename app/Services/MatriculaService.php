<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Encargado;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\Seccion;
use App\Models\User;
use App\Notifications\MatriculaAprobada;
use App\Notifications\MatriculaRechazada;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * M6.1: reglas de matrícula (tesis págs. 57-58 y 63-64). Todas se revisan en
 * el servidor al crear, editar y aprobar, dentro de una transacción que
 * bloquea la sección (cupos) y al estudiante (una matrícula vigente por año).
 *
 * $propietarioId: id de la cuenta ENCARGADO que consulta; null para el
 * DIRECTOR. Lo ajeno responde como no encontrado.
 *
 * Decisiones aprobadas el 13/10/2026:
 * - D-M1: el responsable es el perfil de encargado (encargado_id) o, si va
 *   vacío, el padre o la madre que es titular de la cuenta del estudiante.
 *   Así se identifica a una sola persona aunque estén vinculados ambos padres.
 * - D-M3: la especialidad sale del plan efectivo de la sección; al aprobar no
 *   se admiten especialidades distintas ni planes ambiguos.
 * - D-M4: la sección es obligatoria al solicitar; las pendientes reservan
 *   cupo; al aprobar se confirma sección y cupo con bloqueo.
 * - D-M5: PromocionService (grado correlativo, repetir, sin saltos).
 * - D-M6: avisos en `notifications`; el motivo de rechazo es obligatorio.
 * - D-M7: el encargado edita o cancela solo pendientes; el director cambia la
 *   sección de una aprobada; nadie borra aprobadas ni rechazadas.
 * - D-M8: enfermedad opcional y discapacidades en `expedientes`.
 * - D-M9: foto y documento en el disco privado `matriculas`.
 */
class MatriculaService
{
    public const VIGENTES = ['pendiente', 'aprobado'];

    public const DISCO = 'matriculas';

    public function __construct(private PromocionService $promocion) {}

    public function estudiantes(?int $propietarioId = null)
    {
        return Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId));
    }

    public function matriculas(?int $propietarioId = null)
    {
        return Matricula::query()
            ->when($propietarioId, fn ($q) => $q->whereHas(
                'estudiante',
                fn ($e) => $e->where('user_id', $propietarioId)
            ));
    }

    public function estudiantePropio($estudianteId, ?int $propietarioId = null): Estudiante
    {
        return $this->estudiantes($propietarioId)->findOrFail($estudianteId);
    }

    // El encargado de una matrícula pedida por una cuenta ENCARGADO debe ser su propio perfil
    public function encargadoPermitido($encargadoId, ?int $propietarioId = null): bool
    {
        if ($encargadoId === null || $propietarioId === null) {
            return true;
        }

        return Encargado::where('id', $encargadoId)
            ->where('user_id', $propietarioId)
            ->exists();
    }

    /** Filtros: estado, ingreso, anio_lectivo_id, grado_id, q (nombre o NIE). Incluye el contador por estado. */
    public function listar(array $filtros = [], ?int $propietarioId = null): array
    {
        $base = $this->matriculas($propietarioId)
            ->when($filtros['ingreso'] ?? null, fn ($q, $v) => $q->where('ingreso', $v))
            ->when($filtros['anio_lectivo_id'] ?? null, fn ($q, $v) => $q->where('anio_lectivo_id', $v))
            ->when($filtros['grado_id'] ?? null, fn ($q, $v) => $q->whereHas('seccion', fn ($s) => $s->where('grado_id', $v)))
            ->when(trim($filtros['q'] ?? '') !== '', function ($q) use ($filtros) {
                $texto = trim($filtros['q']);
                $q->whereHas('estudiante', fn ($e) => $e->where('nombre', 'like', "%{$texto}%")->orWhere('nie', 'like', "{$texto}%"));
            });

        $resumen = (clone $base)->select('estado', DB::raw('COUNT(*) as total'))->groupBy('estado')->pluck('total', 'estado');

        $matriculas = (clone $base)
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->with([
                'estudiante:id,nombre,nie,user_id',
                'especialidad:id,nombre',
                'anioLectivo:id,nombre,estado',
                'seccion:id,nombre,turno,grado_id,capacidad',
                'seccion.grado:id,nombre',
            ])
            ->orderByDesc('id')
            ->get();

        return [
            'matriculas' => $matriculas,
            'resumen' => collect(['pendiente', 'aprobado', 'rechazado'])->mapWithKeys(fn ($e) => [$e => (int) ($resumen[$e] ?? 0)]),
        ];
    }

    /** Expediente completo (pág. 63): estudiante, responsable, salud, sección, archivos y motivo de rechazo. */
    public function obtener($id, ?int $propietarioId = null): array
    {
        $matricula = $this->matriculas($propietarioId)->with([
            'estudiante.padres:id,nombre,dui,telefono,email,user_id',
            'estudiante.distrito.municipio.departamento',
            'especialidad:id,nombre',
            'anioLectivo:id,nombre,estado',
            'seccion:id,nombre,turno,grado_id,capacidad,anio_lectivo_id',
            'seccion.grado:id,nombre',
            'encargado',
            'enfermedad',
            'discapacidades:id,nombre',
        ])->findOrFail($id);

        return $matricula->toArray() + [
            'responsable' => $this->responsableDe($matricula),
            'motivo_rechazo' => $matricula->estado === 'rechazado' ? $this->motivoRechazo($matricula) : null,
            'archivos' => ['foto' => (bool) $matricula->foto, 'documento' => (bool) $matricula->documento],
        ];
    }

    public function crear(array $datos, UploadedFile $foto, ?UploadedFile $documento, User $cuenta, ?int $propietarioId = null): Matricula
    {
        $anio = $this->calendario()->anioParaMatricula($datos['anio_lectivo_id'] ?? null);
        $guardados = [];

        try {
            return DB::transaction(function () use ($datos, $foto, $documento, $cuenta, $propietarioId, $anio, &$guardados) {
                $estudiante = $this->estudiantes($propietarioId)->lockForUpdate()->findOrFail($datos['estudiante_id']);
                $seccion = $this->seccionDelAnio($datos['seccion_id'], $anio);

                $this->sinOtraVigente($estudiante, $anio);
                $especialidad = $this->validarGradoYPlan($estudiante, $anio, $seccion, $datos['ingreso'], $this->especialidadPedida($datos));
                $encargadoId = $this->validarResponsable($estudiante, $datos['encargado_id'] ?? null, $propietarioId);
                $this->validarCupo($seccion);

                $guardados[] = $rutaFoto = $this->guardarArchivo($foto, 'fotos', 'foto');
                $rutaDocumento = $documento ? ($guardados[] = $this->guardarArchivo($documento, 'documentos', 'documento')) : null;

                $matricula = Matricula::create([
                    'ingreso' => $datos['ingreso'],
                    'anio_lectivo_id' => $anio->id,
                    'estado' => 'pendiente',
                    'foto' => $rutaFoto,
                    'documento' => $rutaDocumento,
                    'es_trasladado' => (bool) $datos['es_trasladado'],
                    'estudiante_id' => $estudiante->id,
                    'enfermedad_id' => $datos['enfermedad_id'] ?? null,
                    'seccion_id' => $seccion->id,
                    'especialidad_id' => $especialidad,
                    'encargado_id' => $encargadoId,
                ]);
                $matricula->discapacidades()->sync($datos['discapacidades'] ?? []);

                $this->bitacora('matricula_solicitada', $cuenta, $matricula);

                return $matricula->fresh(['discapacidades:id,nombre']);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISCO)->delete($guardados);
            throw $e;
        }
    }

    /**
     * D-M7: solo una matrícula pendiente se edita (encargado o director). No
     * cambian el estudiante, el año ni el tipo de ingreso: para eso se cancela
     * y se solicita de nuevo. Solo se actualizan los campos enviados.
     */
    public function actualizar($id, array $datos, ?UploadedFile $foto, ?UploadedFile $documento, User $cuenta, ?int $propietarioId = null): Matricula
    {
        $guardados = [];
        $anteriores = [];

        try {
            $matricula = DB::transaction(function () use ($id, $datos, $foto, $documento, $cuenta, $propietarioId, &$guardados, &$anteriores) {
                $matricula = $this->matriculas($propietarioId)->lockForUpdate()->findOrFail($id);
                if ($matricula->estado !== 'pendiente') {
                    throw new \DomainException('Solo se puede editar una matrícula pendiente.'.($matricula->estado === 'aprobado' ? ' La dirección puede cambiar la sección de una matrícula aprobada.' : ''));
                }

                $estudiante = Estudiante::lockForUpdate()->findOrFail($matricula->estudiante_id);
                $cambios = [];

                $seccionId = $datos['seccion_id'] ?? $matricula->seccion_id;
                if ($seccionId) {
                    $anio = $matricula->anioLectivo ?? $this->calendario()->anioParaMatricula(null);
                    if ($anio->estado === AnioLectivo::CIERRE) {
                        throw new \DomainException("El año lectivo {$anio->nombre} está cerrado.");
                    }
                    $seccion = $this->seccionDelAnio($seccionId, $anio);
                    $pedida = array_key_exists('especialidad_id', $datos) ? $this->especialidadPedida($datos) : PromocionService::id($matricula->especialidad_id);
                    $cambios['especialidad_id'] = $this->validarGradoYPlan($estudiante, $anio, $seccion, $matricula->ingreso, $pedida);
                    if ((int) $seccionId !== (int) $matricula->seccion_id) {
                        $this->validarCupo($seccion, $matricula->id);
                    }
                    $cambios['seccion_id'] = $seccion->id;
                } elseif (array_key_exists('especialidad_id', $datos)) {
                    // Sin sección aún: se revisará contra el plan al asignarla o al aprobar
                    $cambios['especialidad_id'] = $datos['especialidad_id'];
                }

                if (array_key_exists('encargado_id', $datos)) {
                    $cambios['encargado_id'] = $this->validarResponsable($estudiante, $datos['encargado_id'], $propietarioId);
                }
                if (array_key_exists('enfermedad_id', $datos)) {
                    $cambios['enfermedad_id'] = $datos['enfermedad_id'];
                }
                if (array_key_exists('es_trasladado', $datos)) {
                    $cambios['es_trasladado'] = (bool) $datos['es_trasladado'];
                }
                if ($foto) {
                    $guardados[] = $cambios['foto'] = $this->guardarArchivo($foto, 'fotos', 'foto');
                    $anteriores[] = $matricula->foto;
                }
                if ($documento) {
                    $guardados[] = $cambios['documento'] = $this->guardarArchivo($documento, 'documentos', 'documento');
                    $anteriores[] = $matricula->documento;
                }

                $matricula->update($cambios);
                if (array_key_exists('discapacidades', $datos)) {
                    $matricula->discapacidades()->sync($datos['discapacidades'] ?? []);
                }

                return $matricula;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISCO)->delete($guardados);
            throw $e;
        }

        $this->borrarArchivos($anteriores);

        return $matricula->fresh(['discapacidades:id,nombre']);
    }

    /** D-M7: el encargado (o la dirección) cancela una solicitud pendiente; se borra con sus archivos. */
    public function cancelar($id, User $cuenta, ?int $propietarioId = null): void
    {
        $archivos = DB::transaction(function () use ($id, $cuenta, $propietarioId) {
            $matricula = $this->matriculas($propietarioId)->lockForUpdate()->findOrFail($id);
            if ($matricula->estado !== 'pendiente') {
                throw new \DomainException('Solo se puede cancelar una matrícula pendiente. Las aprobadas y rechazadas se conservan como historial.');
            }

            $this->bitacora('matricula_cancelada', $cuenta, $matricula);
            $matricula->discapacidades()->detach();
            $matricula->delete();

            return [$matricula->foto, $matricula->documento];
        });

        $this->borrarArchivos($archivos);
    }

    /**
     * Aprobar (solo DIRECTOR, págs. 63-64): confirma sección, cupo, grado,
     * plan y responsable con bloqueo; activa al estudiante y avisa a la cuenta.
     */
    public function aprobar($id, ?int $seccionId, User $director): Matricula
    {
        $matricula = DB::transaction(function () use ($id, $seccionId, $director) {
            $matricula = Matricula::lockForUpdate()->findOrFail($id);
            $this->exigirPendiente($matricula);

            $seccionId ??= $matricula->seccion_id;
            if (! $seccionId) {
                throw ValidationException::withMessages(['seccion_id' => 'Asigne una sección antes de aprobar la matrícula.']);
            }

            $anio = AnioLectivo::findOrFail($matricula->anio_lectivo_id);
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException("El año lectivo {$anio->nombre} está cerrado: no se aprueban matrículas.");
            }

            $estudiante = Estudiante::lockForUpdate()->findOrFail($matricula->estudiante_id);
            $seccion = $this->seccionDelAnio($seccionId, $anio);

            $this->sinOtraVigente($estudiante, $anio, $matricula->id);
            $especialidad = $this->validarGradoYPlan($estudiante, $anio, $seccion, $matricula->ingreso, PromocionService::id($matricula->especialidad_id), true);
            $this->validarResponsable($estudiante, $matricula->encargado_id, null);
            $this->validarCupo($seccion, $matricula->id, true);

            $matricula->update(['seccion_id' => $seccion->id, 'especialidad_id' => $especialidad, 'estado' => 'aprobado']);
            $estudiante->update(['estado' => 'activo']);

            $this->bitacora('matricula_aprobada', $director, $matricula, ['seccion_id' => $seccion->id]);

            return $matricula;
        });

        $matricula->estudiante?->user?->notify(new MatriculaAprobada($matricula));

        return $matricula->fresh(['seccion.grado:id,nombre', 'anioLectivo:id,nombre']);
    }

    /** Rechazar (solo DIRECTOR, pág. 64): motivo obligatorio, aviso a la cuenta y bitácora. */
    public function rechazar($id, string $motivo, User $director): Matricula
    {
        $matricula = DB::transaction(function () use ($id, $motivo, $director) {
            $matricula = Matricula::lockForUpdate()->findOrFail($id);
            $this->exigirPendiente($matricula);
            $matricula->update(['estado' => 'rechazado']);
            $this->bitacora('matricula_rechazada', $director, $matricula, ['motivo' => $motivo]);

            return $matricula;
        });

        $matricula->estudiante?->user?->notify(new MatriculaRechazada($matricula, $motivo));

        return $matricula;
    }

    /** D-M7: la dirección cambia la sección de una matrícula pendiente o aprobada (mismo grado y año, con cupo). */
    public function cambiarSeccion($id, int $seccionId, User $director): Matricula
    {
        return DB::transaction(function () use ($id, $seccionId, $director) {
            $matricula = Matricula::with('seccion')->lockForUpdate()->findOrFail($id);
            if (! in_array($matricula->estado, self::VIGENTES, true)) {
                throw new \DomainException('Solo se cambia la sección de una matrícula pendiente o aprobada.');
            }

            $anio = AnioLectivo::findOrFail($matricula->anio_lectivo_id);
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException("El año lectivo {$anio->nombre} está cerrado.");
            }

            $seccion = $this->seccionDelAnio($seccionId, $anio);
            if ($matricula->seccion && (int) $matricula->seccion->grado_id !== (int) $seccion->grado_id) {
                throw ValidationException::withMessages(['seccion_id' => 'La nueva sección debe ser del mismo grado.']);
            }

            $estudiante = Estudiante::findOrFail($matricula->estudiante_id);
            $especialidad = $this->validarGradoYPlan($estudiante, $anio, $seccion, $matricula->ingreso, PromocionService::id($matricula->especialidad_id), $matricula->estado === 'aprobado');
            $this->validarCupo($seccion, $matricula->id);

            $anterior = $matricula->seccion_id;
            $matricula->update(['seccion_id' => $seccion->id, 'especialidad_id' => $especialidad]);
            $this->bitacora('seccion_cambiada', $director, $matricula, ['seccion_anterior' => $anterior, 'seccion_nueva' => $seccion->id]);

            return $matricula->fresh(['seccion.grado:id,nombre']);
        });
    }

    /** Descarga privada (D-M9): solo la cuenta dueña o la dirección. */
    public function archivo($id, string $tipo, ?int $propietarioId = null): array
    {
        $matricula = $this->matriculas($propietarioId)->findOrFail($id);
        $ruta = $matricula->{$tipo};

        // Rutas anteriores a M6.1 que aún no se trasladaron (archivo faltante)
        [$disco, $relativa] = str_starts_with((string) $ruta, '/storage/')
            ? ['public', substr($ruta, strlen('/storage/'))]
            : [self::DISCO, $ruta];

        if (! $ruta || ! Storage::disk($disco)->exists($relativa)) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Matricula::class, [$id]);
        }

        return [Storage::disk($disco)->path($relativa), $tipo.'_matricula_'.$matricula->id.'.'.pathinfo($relativa, PATHINFO_EXTENSION)];
    }

    /** Lo que puede matricular el estudiante en el año (para el formulario del encargado). */
    public function opciones($estudianteId, $anioLectivoId = null, ?int $propietarioId = null): array
    {
        $estudiante = $this->estudiantePropio($estudianteId, $propietarioId);
        $anio = $this->calendario()->anioParaMatricula($anioLectivoId);
        $opciones = $this->promocion->opciones($estudiante, $anio);
        $vigente = Matricula::where('estudiante_id', $estudiante->id)->where('anio_lectivo_id', $anio->id)->whereIn('estado', self::VIGENTES)->first(['id', 'estado']);

        return [
            'anio_lectivo' => $anio->only(['id', 'nombre', 'estado']),
            'ingreso' => $opciones['ingreso'],
            'grados' => \App\Models\Grado::whereIn('id', $opciones['grados'])->get(['id', 'nombre'])->sortBy('orden')->values(),
            'especialidad_id' => $opciones['especialidad_id'] === false ? null : $opciones['especialidad_id'],
            'especialidad_libre' => $opciones['especialidad_id'] === false,
            'mensaje' => $vigente ? "Ya tiene una matrícula {$vigente->estado} en {$anio->nombre}." : $opciones['mensaje'],
            'permitido' => ! $vigente && ! $opciones['bloqueo'],
        ];
    }

    public function buscarPorNie($nie, ?int $propietarioId = null)
    {
        $estudiante = $this->estudiantes($propietarioId)->with([
            'matriculas.especialidad',
        ])
            ->where('nie', $nie)
            ->first();

        if (! $estudiante) {
            return null;
        }

        $ultimaMatricula = $estudiante->matriculas
            ->sortByDesc('id')
            ->first();

        return [
            'id' => $estudiante->id,
            'nombre' => $estudiante->nombre,
            'nie' => $estudiante->nie,
            'especialidad' => $ultimaMatricula?->especialidad?->nombre,
        ];
    }

    public function obtenerEstudiantesInscritos(?int $propietarioId = null)
    {
        return $this->estudiantes($propietarioId)->select('id', 'nombre')
            ->where('estado', 'inscrito')
            ->get();
    }

    public function obtenerPadresEstudiante($estudianteId, ?int $propietarioId = null)
    {
        $estudiante = $this->estudiantes($propietarioId)->with('padres')
            ->findOrFail($estudianteId);

        return $estudiante->padres;
    }

    /** Consulta previa (pág. 57): ¿puede matricular ese grado? Usa las mismas reglas que al guardar. */
    public function validarGrado($estudianteId, $grado, $ingreso, ?int $propietarioId = null, $anioLectivoId = null): array
    {
        $estudiante = $this->estudiantePropio($estudianteId, $propietarioId);
        // Consulta previa: sin año indicado ni activo, se compara con todo el historial
        $anio = $anioLectivoId || AnioLectivo::activo() ? $this->calendario()->anioParaMatricula($anioLectivoId) : null;
        $opciones = $this->promocion->opciones($estudiante, $anio);
        $permitido = $opciones['grados'][0] ?? null;

        if ($opciones['bloqueo']) {
            return ['permitido' => false, 'status' => 422, 'message' => $opciones['bloqueo']];
        }
        if ($ingreso !== $opciones['ingreso']) {
            return ['permitido' => false, 'status' => 422, 'message' => $this->mensajeIngreso($opciones['ingreso'])];
        }
        if (! in_array((int) $grado, $opciones['grados'], true)) {
            return ['permitido' => false, 'grado_permitido' => $permitido, 'status' => 422, 'message' => $opciones['mensaje']];
        }

        return ['permitido' => true, 'message' => $opciones['mensaje']];
    }

    public function verificarDisponibilidadSeccion($gradoId, $turno, $anioLectivoId = null)
    {
        $secciones = $this->seccionesDelAnio($gradoId, $turno, $anioLectivoId);

        if ($secciones->isEmpty()) {
            return [
                'disponibilidad' => false,
                'message' => 'No hay secciones disponibles para el grado y turno especificados.',
            ];
        }

        $cuposDisponibles = $secciones->sum(fn ($seccion) => max(0, $seccion->capacidad - $seccion->matriculas_count));

        if ($cuposDisponibles <= 0) {
            return [
                'disponibilidad' => false,
                'message' => 'No hay cupos disponibles en las secciones para el grado y turno especificados.',
            ];
        }

        return [
            'disponibilidad' => true,
            'cupos_disponibles' => $cuposDisponibles,
        ];
    }

    public function obtenerSeccionesDisponibles($gradoId, $turno, $anioLectivoId = null)
    {
        return $this->seccionesDelAnio($gradoId, $turno, $anioLectivoId)
            ->map(fn ($seccion) => [
                'id' => $seccion->id,
                'nombre' => $seccion->nombre,
                'capacidad' => $seccion->capacidad,
                'cupos_disponibles' => max(0, $seccion->capacidad - $seccion->matriculas_count),
            ])
            ->filter(fn ($seccion) => $seccion['cupos_disponibles'] > 0)
            ->values();
    }

    public function verificarDuplicidad($estudianteId, $anioLectivoId = null)
    {
        $anioLectivo = $this->calendario()->anioParaMatricula($anioLectivoId);

        return Matricula::where('estudiante_id', $estudianteId)
            ->where('anio_lectivo_id', $anioLectivo->id)
            ->whereIn('estado', self::VIGENTES)
            ->exists();
    }

    /**
     * D-M1: datos del responsable. Perfil de encargado si lo hay; si no, el
     * padre o la madre titular de la cuenta del estudiante.
     */
    public function responsableDe(Matricula $matricula): ?array
    {
        if ($matricula->encargado_id) {
            $e = $matricula->encargado ?? Encargado::find($matricula->encargado_id);

            return $e ? ['tipo' => 'encargado', 'id' => $e->id, 'nombre' => $e->nombre, 'dui' => $e->dui, 'telefono' => $e->telefono, 'parentesco' => $e->parentesco] : null;
        }

        $padre = $this->padreDeLaCuenta($matricula->estudiante ?? Estudiante::find($matricula->estudiante_id));

        return $padre ? ['tipo' => 'padre', 'id' => $padre->id, 'nombre' => $padre->nombre, 'dui' => $padre->dui, 'telefono' => $padre->telefono, 'parentesco' => $padre->pivot->parentesco] : null;
    }

    public function motivoRechazo(Matricula $matricula): ?string
    {
        $cuenta = $matricula->estudiante?->user_id ?? Estudiante::whereKey($matricula->estudiante_id)->value('user_id');

        $aviso = DatabaseNotification::where('type', MatriculaRechazada::class)
            ->when($cuenta, fn ($q) => $q->where('notifiable_id', $cuenta))
            ->latest()
            ->get()
            ->first(fn ($n) => (int) ($n->data['matricula_id'] ?? 0) === (int) $matricula->id);

        return $aviso?->data['motivo'] ?? null;
    }

    // ---------------------------------------------------------------- reglas

    private function exigirPendiente(Matricula $matricula): void
    {
        if ($matricula->estado !== 'pendiente') {
            throw new \DomainException("La matrícula ya está {$matricula->estado}: solo se aprueba o rechaza una pendiente.");
        }
    }

    /** Sección del año de la matrícula, bloqueada para contar cupos sin carreras. */
    private function seccionDelAnio($seccionId, AnioLectivo $anio): Seccion
    {
        $seccion = Seccion::with('grado')->lockForUpdate()->find($seccionId);
        if (! $seccion) {
            throw ValidationException::withMessages(['seccion_id' => 'La sección seleccionada no existe.']);
        }
        if ((int) $seccion->anio_lectivo_id !== (int) $anio->id) {
            throw ValidationException::withMessages(['seccion_id' => 'La sección no pertenece al año lectivo de la matrícula.']);
        }

        return $seccion;
    }

    /** Pág. 57: sin otra matrícula pendiente o aprobada en el mismo año. */
    private function sinOtraVigente(Estudiante $estudiante, AnioLectivo $anio, ?int $excepto = null): void
    {
        $existe = Matricula::where('estudiante_id', $estudiante->id)
            ->where('anio_lectivo_id', $anio->id)
            ->whereIn('estado', self::VIGENTES)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['estudiante_id' => "{$estudiante->nombre} ya tiene una matrícula pendiente o aprobada en el año {$anio->nombre}."]);
        }
    }

    /**
     * D-M5 y D-M3: tipo de ingreso, grado correlativo y plan de la sección.
     * Devuelve la especialidad que corresponde al plan efectivo.
     */
    private function validarGradoYPlan(Estudiante $estudiante, AnioLectivo $anio, Seccion $seccion, string $ingreso, int|false|null $pedida, bool $estricto = false): ?int
    {
        $opciones = $this->promocion->opciones($estudiante, $anio);
        if ($opciones['bloqueo']) {
            throw ValidationException::withMessages(['seccion_id' => $opciones['bloqueo']]);
        }
        if ($ingreso !== $opciones['ingreso']) {
            throw ValidationException::withMessages(['ingreso' => $this->mensajeIngreso($opciones['ingreso'])]);
        }
        if (! in_array((int) $seccion->grado_id, $opciones['grados'], true)) {
            throw ValidationException::withMessages(['seccion_id' => $ingreso === 'Reingreso'
                ? $opciones['mensaje'].' La sección elegida es de otro grado.'
                : 'El grado de la sección no pertenece al catálogo oficial de grados.']);
        }

        // Continuidad de especialidad en la promoción
        if ($opciones['especialidad_id'] !== false) {
            if ($pedida !== false && $pedida !== $opciones['especialidad_id']) {
                throw ValidationException::withMessages(['especialidad_id' => 'En reingreso se conserva la especialidad del año anterior.']);
            }
            $pedida = $opciones['especialidad_id'];
        }

        $resultado = $this->promocion->planEfectivo($seccion, $pedida, $estricto);
        if ($resultado['error']) {
            throw ValidationException::withMessages([$resultado['campo'] => $resultado['error']]);
        }

        return PromocionService::id($resultado['plan']->especialidad_id);
    }

    private function mensajeIngreso(?string $esperado): string
    {
        return $esperado === 'Reingreso'
            ? 'El estudiante tiene matrículas aprobadas en SIGA: corresponde Reingreso, con validación de promoción.'
            : 'El estudiante no tiene matrículas aprobadas en SIGA: corresponde Nuevo ingreso.';
    }

    /**
     * D-M1: con encargado_id, ese perfil debe ser de la cuenta del estudiante
     * (la dirección puede usar también uno sin cuenta). Sin él, la cuenta del
     * estudiante debe estar vinculada como padre o madre del estudiante.
     */
    private function validarResponsable(Estudiante $estudiante, $encargadoId, ?int $propietarioId): ?int
    {
        if ($encargadoId) {
            $encargado = Encargado::find($encargadoId);
            $valido = $encargado && (
                (int) $encargado->user_id === (int) $estudiante->user_id
                || ($propietarioId === null && $encargado->user_id === null)
            );
            if (! $valido) {
                throw ValidationException::withMessages(['encargado_id' => 'El encargado seleccionado no es válido para este estudiante.']);
            }

            return $encargado->id;
        }

        if (! $this->padreDeLaCuenta($estudiante)) {
            throw ValidationException::withMessages(['encargado_id' => 'Indique el responsable: la cuenta del estudiante no está vinculada como su padre o madre. Registre sus datos de padre o madre o su perfil de encargado.']);
        }

        return null;
    }

    private function padreDeLaCuenta(?Estudiante $estudiante): ?Padre
    {
        if (! $estudiante?->user_id) {
            return null;
        }

        return $estudiante->padres()->where('padres.user_id', $estudiante->user_id)->first();
    }

    /**
     * D-M4: al solicitar, las pendientes y aprobadas ocupan cupo (reserva). Al
     * aprobar ($alAprobar) solo cuentan las aprobadas: así una sección que quedó
     * sobrerreservada con datos anteriores no bloquea todas sus aprobaciones,
     * y nunca se aprueban más estudiantes que la capacidad.
     */
    private function validarCupo(Seccion $seccion, ?int $excepto = null, bool $alAprobar = false): void
    {
        $ocupados = Matricula::where('seccion_id', $seccion->id)
            ->where('anio_lectivo_id', $seccion->anio_lectivo_id)
            ->whereIn('estado', $alAprobar ? ['aprobado'] : self::VIGENTES)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->count();

        if ($ocupados >= $seccion->capacidad) {
            throw ValidationException::withMessages(['seccion_id' => "La sección {$seccion->grado?->nombre} {$seccion->nombre} no tiene cupos disponibles ({$ocupados} de {$seccion->capacidad})."]);
        }
    }

    private function especialidadPedida(array $datos): int|false|null
    {
        if (! array_key_exists('especialidad_id', $datos) || $datos['especialidad_id'] === '') {
            return false;
        }

        return PromocionService::id($datos['especialidad_id']);
    }

    private function guardarArchivo(UploadedFile $archivo, string $carpeta, string $prefijo): string
    {
        $nombre = $prefijo.'_'.bin2hex(random_bytes(12)).'.'.strtolower($archivo->getClientOriginalExtension());
        Storage::disk(self::DISCO)->putFileAs($carpeta, $archivo, $nombre);

        return "{$carpeta}/{$nombre}";
    }

    private function borrarArchivos(array $rutas): void
    {
        foreach (array_filter($rutas) as $ruta) {
            if (str_starts_with($ruta, '/storage/')) {
                Storage::disk('public')->delete(substr($ruta, strlen('/storage/')));
            } else {
                Storage::disk(self::DISCO)->delete($ruta);
            }
        }
    }

    private function bitacora(string $accion, User $autor, Matricula $matricula, array $extra = []): void
    {
        Log::channel('matriculas')->info($accion, [
            'matricula_id' => $matricula->id,
            'estudiante_id' => $matricula->estudiante_id,
            'anio_lectivo_id' => $matricula->anio_lectivo_id,
            'seccion_id' => $matricula->seccion_id,
            'estado' => $matricula->estado,
        ] + $extra + ['por' => "{$autor->id} {$autor->email}"]);
    }

    /**
     * M2.2: secciones del grado y turno en el año lectivo indicado (o el activo,
     * en lugar del año del reloj del servidor), con sus matrículas vigentes de
     * ese mismo año.
     */
    private function seccionesDelAnio($gradoId, $turno, $anioLectivoId = null)
    {
        $anioLectivo = $this->calendario()->anioParaMatricula($anioLectivoId);

        return Seccion::where('grado_id', $gradoId)
            ->where('turno', $turno)
            ->where('anio_lectivo_id', $anioLectivo->id)
            ->withCount([
                'matriculas' => function ($query) use ($anioLectivo) {
                    $query->where('anio_lectivo_id', $anioLectivo->id)
                        ->whereIn('estado', self::VIGENTES);
                },
            ])
            ->get();
    }

    private function calendario(): CalendarioService
    {
        return app(CalendarioService::class);
    }
}
