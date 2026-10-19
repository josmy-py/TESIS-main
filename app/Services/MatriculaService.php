<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Encargado;
use App\Models\Enfermedad;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
 * - D-M4 (ajuste de Josmy, 13/10/2026): el encargado llena la solicitud sin
 *   sección; la sección la asigna la dirección (al registrar, editar o
 *   aprobar), solo entre las de un grado permitido y con cupo. Al aprobar se
 *   confirma sección y cupo con bloqueo.
 * - Responsable (ajuste de Josmy): si el padre o la madre es la cuenta, es el
 *   responsable y no hay encargado; si no, el responsable es el perfil de
 *   encargado de la cuenta (encargados.user_id), obligatorio.
 * - NIE (se guarda en `estudiantes`): el encargado lo ingresa en Reingreso o si
 *   el estudiante viene trasladado; en Nuevo ingreso sin traslado no puede, lo
 *   registra la dirección al aprobar.
 * - D-M5: PromocionService (grado correlativo, repetir, sin saltos).
 * - D-M7: el encargado edita o cancela solo pendientes; nadie borra aprobadas
 *   ni rechazadas.
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
                'anioLectivo:id,anio,estado',
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

    /** Expediente completo (pág. 63): estudiante, responsable, salud, sección, y archivos. */
    public function obtener($id, ?int $propietarioId = null): array
    {
        $matricula = $this->matriculas($propietarioId)->with([
            'estudiante.padres:id,nombre,dui,telefono,email,user_id',
            'estudiante.distrito.municipio.departamento',
            'estudiante.user:id,email',
            'especialidad:id,nombre',
            'anioLectivo:id,anio,estado',
            'seccion:id,nombre,turno,grado_id,capacidad,anio_lectivo_id',
            'seccion.grado:id,nombre',
            'encargado',
            'enfermedad',
            'discapacidades:id,nombre',
        ])->findOrFail($id);

        return $matricula->toArray() + [
            'responsable' => $this->responsableDe($matricula),
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
                // La sección la indica la dirección. El encargado indica grado y turno: queda
                // una sección de ese grado y turno con cupo, que la dirección confirma o cambia al aprobar
                $seccion = ! empty($datos['seccion_id']) ? $this->seccionDelAnio($datos['seccion_id'], $anio) : null;

                $this->sinOtraVigente($estudiante, $anio);
                if ($seccion) {
                    $especialidad = $this->validarGradoYPlan($estudiante, $anio, $seccion, $datos['ingreso'], $this->especialidadPedida($datos));
                } else {
                    $promocion = $this->validarPromocion($estudiante, $anio, $datos['ingreso'], $this->especialidadPedida($datos));
                    $especialidad = $promocion['especialidad'];
                    if (! empty($datos['grado_id'])) {
                        $seccion = $this->seccionSolicitada((int) $datos['grado_id'], $datos['turno'], $anio, $promocion['opciones']);
                    }
                }
                $encargadoId = $this->validarResponsable($estudiante, $datos['encargado_id'] ?? null, $propietarioId);
                if ($seccion) {
                    $this->validarCupo($seccion);
                }
                if (array_key_exists('nie', $datos)) {
                    $this->guardarNie($estudiante, $datos['nie'], $propietarioId);
                }

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
                    'enfermedad_id' => $this->enfermedadConMedicamento($datos['enfermedad_id'] ?? null, $datos['medicamento'] ?? null),
                    'seccion_id' => $seccion?->id,
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
                        throw new \DomainException("El año lectivo {$anio->anio} está cerrado.");
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
                    $cambios['especialidad_id'] = $matricula->anioLectivo
                        ? $this->validarPromocion($estudiante, $matricula->anioLectivo, $matricula->ingreso, $this->especialidadPedida($datos))['especialidad']
                        : $datos['especialidad_id'];
                }

                if (array_key_exists('encargado_id', $datos)) {
                    $cambios['encargado_id'] = $this->validarResponsable($estudiante, $datos['encargado_id'], $propietarioId);
                }
                if (array_key_exists('nie', $datos)) {
                    $this->guardarNie($estudiante, $datos['nie'], $propietarioId);
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
    public function aprobar($id, ?int $seccionId, User $director, ?string $nie = null): Matricula
    {
        $matricula = DB::transaction(function () use ($id, $seccionId, $director, $nie) {
            $matricula = Matricula::lockForUpdate()->findOrFail($id);
            $this->exigirPendiente($matricula);

            $seccionId ??= $matricula->seccion_id;
            if (! $seccionId) {
                throw ValidationException::withMessages(['seccion_id' => 'Asigne una sección antes de aprobar la matrícula.']);
            }

            $anio = AnioLectivo::findOrFail($matricula->anio_lectivo_id);
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException("El año lectivo {$anio->anio} está cerrado: no se aprueban matrículas.");
            }

            $estudiante = Estudiante::lockForUpdate()->findOrFail($matricula->estudiante_id);
            $seccion = $this->seccionDelAnio($seccionId, $anio);

            $this->sinOtraVigente($estudiante, $anio, $matricula->id);
            $especialidad = $this->validarGradoYPlan($estudiante, $anio, $seccion, $matricula->ingreso, PromocionService::id($matricula->especialidad_id));
            $this->delGradoYTurnoPedidos($matricula, $seccion);
            $this->validarResponsable($estudiante, $matricula->encargado_id, null);
            $this->validarCupo($seccion, $matricula->id, true);

            // NIE (ajuste de Josmy): si el estudiante no lo tiene (Nuevo ingreso sin traslado), lo registra la dirección al aprobar
            if ($nie) {
                $this->guardarNie($estudiante, $nie, null);
            } elseif (! $estudiante->nie) {
                throw ValidationException::withMessages(['nie' => 'Ingrese el NIE del estudiante para aprobar la matrícula.']);
            }

            $matricula->update(['seccion_id' => $seccion->id, 'especialidad_id' => $especialidad, 'estado' => 'aprobado']);
            $estudiante->update(['estado' => 'activo']);

            $this->bitacora('matricula_aprobada', $director, $matricula, ['seccion_id' => $seccion->id]);

            return $matricula;
        });

        return $matricula->fresh(['seccion.grado:id,nombre', 'anioLectivo:id,anio']);
    }

    /** Rechazar (solo DIRECTOR, pág. 64), con bitácora. */
    public function rechazar($id, User $director): Matricula
    {
        return DB::transaction(function () use ($id, $director) {
            $matricula = Matricula::lockForUpdate()->findOrFail($id);
            $this->exigirPendiente($matricula);
            $matricula->update(['estado' => 'rechazado']);
            $this->bitacora('matricula_rechazada', $director, $matricula);

            return $matricula;
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
            'anio_lectivo' => $anio->only(['id', 'anio', 'estado']),
            'ingreso' => $opciones['ingreso'],
            // Cada grado con los turnos que aún tienen secciones con cupo (sin secciones o llenas, no aparece el turno)
            'grados' => \App\Models\Grado::whereIn('id', $opciones['grados'])->get(['id', 'nombre'])->sortBy('orden')->values()
                ->map(fn ($g) => $g->only(['id', 'nombre']) + [
                    'nivel' => \App\Support\CatalogoGrados::nivel($g->orden),
                    'turnos' => $this->turnosConCupo($g->id, $anio),
                ]),
            'repite' => (bool) ($opciones['repite'] ?? false),
            'especialidad_id' => $opciones['especialidad_id'] === false ? null : $opciones['especialidad_id'],
            'especialidad_libre' => $opciones['especialidad_id'] === false,
            'mensaje' => $vigente ? "Ya tiene una matrícula {$vigente->estado} en {$anio->anio}." : $opciones['mensaje'],
            'permitido' => ! $vigente && ! $opciones['bloqueo'],
            'nie' => $estudiante->nie,
            'responsable' => $this->responsablePara($estudiante),
        ];
    }

    /** Turnos del grado con alguna sección del año con cupo (cuentan aprobadas y pendientes con sección). */
    private function turnosConCupo(int $gradoId, AnioLectivo $anio): array
    {
        return Seccion::where('grado_id', $gradoId)->where('anio_lectivo_id', $anio->id)
            ->withCount(['matriculas as ocupados' => fn ($q) => $q->where('anio_lectivo_id', $anio->id)->whereIn('estado', self::VIGENTES)])
            ->get()
            ->filter(fn (Seccion $s) => $s->ocupados < $s->capacidad)
            ->pluck('turno')->unique()
            ->sortBy(fn ($t) => $t === 'matutino' ? 0 : 1)->values()->all();
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

    /**
     * Para la dirección: secciones donde se puede ubicar la matrícula. Solo
     * grados permitidos por la promoción, y de cada grado solo los turnos y
     * las secciones con cupo; un grado o un turno sin secciones con cupo
     * no aparece. El cupo se cuenta como al aprobar (matrículas aprobadas,
     * sin contar esta).
     */
    public function seccionesParaAsignar($id): array
    {
        $matricula = Matricula::with('estudiante', 'seccion:id,grado_id,turno')->findOrFail($id);
        $anio = AnioLectivo::findOrFail($matricula->anio_lectivo_id);
        $opciones = $this->promocion->opciones($matricula->estudiante, $anio);
        // El responsable ya indicó grado y turno: la dirección solo elige la sección
        $pedida = $matricula->seccion;

        $secciones = Seccion::with('grado:id,nombre')
            ->where('anio_lectivo_id', $anio->id)
            ->whereIn('grado_id', $opciones['grados'])
            ->when($pedida, fn ($q) => $q->where('grado_id', $pedida->grado_id)->where('turno', $pedida->turno))
            ->withCount(['matriculas as ocupados' => fn ($q) => $q->where('anio_lectivo_id', $anio->id)
                ->where('estado', 'aprobado')->whereKeyNot($matricula->id)])
            ->orderBy('nombre')
            ->get()
            ->map(fn (Seccion $s) => [
                'id' => $s->id, 'nombre' => $s->nombre, 'turno' => $s->turno, 'grado_id' => $s->grado_id,
                'grado' => $s->grado?->nombre, 'capacidad' => $s->capacidad, 'cupos_disponibles' => max(0, $s->capacidad - $s->ocupados),
            ])
            ->filter(fn ($s) => $s['cupos_disponibles'] > 0);

        $grados = $secciones->groupBy('grado_id')->map(fn ($deGrado) => [
            'id' => $deGrado->first()['grado_id'],
            'nombre' => $deGrado->first()['grado'],
            'turnos' => $deGrado->groupBy('turno')->map(fn ($deTurno, $turno) => [
                'turno' => $turno,
                'secciones' => $deTurno->map(fn ($s) => collect($s)->only(['id', 'nombre', 'capacidad', 'cupos_disponibles']))->values(),
            ])->sortBy(fn ($t) => $t['turno'] === 'matutino' ? 0 : 1)->values(),
        ])->values();

        return [
            'grados' => $grados,
            'mensaje' => $opciones['bloqueo'] ?? ($grados->isEmpty()
                ? 'No hay secciones con cupo para el grado que corresponde al estudiante en este año lectivo.'
                : $opciones['mensaje']),
        ];
    }

    /**
     * El grado y el turno los indica el responsable en la solicitud (pedido de
     * Josmy, 19/10/2026): al aprobar, la dirección solo puede elegir otra
     * sección de ese mismo grado y turno.
     */
    private function delGradoYTurnoPedidos(Matricula $matricula, Seccion $seccion): void
    {
        $pedida = $matricula->seccion_id ? Seccion::with('grado:id,nombre')->find($matricula->seccion_id) : null;
        if ($pedida && ($pedida->grado_id !== $seccion->grado_id || $pedida->turno !== $seccion->turno)) {
            throw ValidationException::withMessages([
                'seccion_id' => "La sección debe ser de {$pedida->grado?->nombre}, turno {$pedida->turno}: es lo que pidió el responsable.",
            ]);
        }
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

    /**
     * Grado y turno que pide el encargado: el grado debe ser el que permite la
     * promoción y se toma la sección de ese grado y turno con más cupos.
     */
    private function seccionSolicitada(int $gradoId, string $turno, AnioLectivo $anio, array $opciones): Seccion
    {
        if (! in_array($gradoId, $opciones['grados'], true)) {
            throw ValidationException::withMessages(['grado_id' => $opciones['ingreso'] === 'Reingreso'
                ? $opciones['mensaje']
                : 'El grado no pertenece al catálogo oficial de grados.']);
        }

        $seccion = Seccion::with('grado')->lockForUpdate()
            ->where('grado_id', $gradoId)->where('turno', $turno)->where('anio_lectivo_id', $anio->id)
            ->withCount(['matriculas as ocupados' => fn ($q) => $q->where('anio_lectivo_id', $anio->id)->whereIn('estado', self::VIGENTES)])
            ->orderBy('nombre')
            ->get()
            ->filter(fn (Seccion $s) => $s->ocupados < $s->capacidad)
            ->sortByDesc(fn (Seccion $s) => $s->capacidad - $s->ocupados)
            ->first();

        if (! $seccion) {
            $nombreTurno = $turno === 'matutino' ? 'mañana' : 'tarde';
            throw ValidationException::withMessages(['turno' => "No hay secciones con cupo de este grado en el turno de la {$nombreTurno} para {$anio->anio}."]);
        }

        return $seccion;
    }

    /**
     * El medicamento es parte de la enfermedad (tabla enfermedades: nombre único y
     * medicamento). Si el encargado escribe otro medicamento que el del catálogo,
     * se usa (o se crea) la enfermedad «Nombre (medicamento)» con ese medicamento.
     */
    private function enfermedadConMedicamento($enfermedadId, ?string $medicamento): ?int
    {
        $medicamento = trim(preg_replace('/\s+/u', ' ', (string) $medicamento));
        if (! $enfermedadId) {
            if ($medicamento !== '') {
                throw ValidationException::withMessages(['enfermedad_id' => 'Seleccione la enfermedad a la que corresponde el medicamento.']);
            }

            return null;
        }

        $enfermedad = Enfermedad::findOrFail($enfermedadId);
        if ($medicamento === '' || mb_strtolower($medicamento) === mb_strtolower($enfermedad->medicamento)) {
            return $enfermedad->id;
        }

        $base = preg_replace('/\s*\(.*\)$/u', '', $enfermedad->nombre);
        $nombre = mb_substr("{$base} ({$medicamento})", 0, 50);

        return Enfermedad::firstOrCreate(['nombre' => $nombre], ['medicamento' => $medicamento])->id;
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
            throw ValidationException::withMessages(['estudiante_id' => "{$estudiante->nombre} ya tiene una matrícula pendiente o aprobada en el año {$anio->anio}."]);
        }
    }

    /**
     * D-M5 y D-M3: tipo de ingreso, grado correlativo y plan de la sección.
     * Devuelve la especialidad que corresponde al plan efectivo.
     */
    private function validarGradoYPlan(Estudiante $estudiante, AnioLectivo $anio, Seccion $seccion, string $ingreso, int|false|null $pedida): ?int
    {
        ['opciones' => $opciones, 'pedida' => $pedida] = $this->validarPromocion($estudiante, $anio, $ingreso, $pedida, 'seccion_id');
        if (! in_array((int) $seccion->grado_id, $opciones['grados'], true)) {
            throw ValidationException::withMessages(['seccion_id' => $ingreso === 'Reingreso'
                ? $opciones['mensaje'].' La sección elegida es de otro grado.'
                : 'El grado de la sección no pertenece al catálogo oficial de grados.']);
        }

        $resultado = $this->promocion->planEfectivo($seccion, $pedida);
        if ($resultado['error']) {
            throw ValidationException::withMessages([$resultado['campo'] => $resultado['error']]);
        }

        if ($resultado['plan']) {
            return PromocionService::id($resultado['plan']->especialidad_id);
        }

        return $pedida === false ? null : $pedida;
    }

    /**
     * D-M5: el estudiante debe haber aprobado su grado anterior para pasar al
     * siguiente (si reprobó, solo repite); sin nota anual o sin grado
     * siguiente se bloquea. También revisa el tipo de ingreso y la
     * continuidad de la especialidad. Sirve aunque aún no haya sección.
     *
     * @return array{opciones: array, pedida: int|false|null, especialidad: ?int}
     */
    private function validarPromocion(Estudiante $estudiante, AnioLectivo $anio, string $ingreso, int|false|null $pedida, string $campo = 'estudiante_id'): array
    {
        $opciones = $this->promocion->opciones($estudiante, $anio);
        if ($opciones['bloqueo']) {
            throw ValidationException::withMessages([$campo => $opciones['bloqueo']]);
        }
        if ($ingreso !== $opciones['ingreso']) {
            throw ValidationException::withMessages(['ingreso' => $this->mensajeIngreso($opciones['ingreso'])]);
        }

        // Continuidad de especialidad en la promoción
        if ($opciones['especialidad_id'] !== false) {
            if ($pedida !== false && $pedida !== $opciones['especialidad_id']) {
                throw ValidationException::withMessages(['especialidad_id' => 'En reingreso se conserva la especialidad del año anterior.']);
            }
            $pedida = $opciones['especialidad_id'];
        }

        return ['opciones' => $opciones, 'pedida' => $pedida, 'especialidad' => $pedida === false ? null : $pedida];
    }

    /** El NIE se captura en la matrícula y se guarda en el estudiante (único). */
    private function guardarNie(Estudiante $estudiante, string $nie, ?int $propietarioId): void
    {
        if ($nie === (string) $estudiante->nie) {
            return;
        }
        if (Estudiante::where('nie', $nie)->whereKeyNot($estudiante->id)->exists()) {
            throw ValidationException::withMessages(['nie' => 'El NIE ya está registrado; contacte a la dirección.']);
        }
        if ($propietarioId && $estudiante->nie && Matricula::where('estudiante_id', $estudiante->id)->where('estado', 'aprobado')->exists()) {
            throw ValidationException::withMessages(['nie' => 'El estudiante ya tiene una matrícula aprobada: solo la dirección puede cambiar su NIE.']);
        }

        $estudiante->update(['nie' => $nie]);
    }

    private function mensajeIngreso(?string $esperado): string
    {
        return $esperado === 'Reingreso'
            ? 'El estudiante tiene matrículas aprobadas en SIGA: corresponde Reingreso, con validación de promoción.'
            : 'El estudiante no tiene matrículas aprobadas en SIGA: corresponde Nuevo ingreso.';
    }

    /**
     * Responsable (ajuste de Josmy, 13/10/2026). La cuenta del estudiante es
     * su responsable:
     * - si es el padre o la madre (padres.user_id), el responsable es ese
     *   registro y no se admite encargado adicional (encargado_id vacío);
     * - si no, es obligatorio su perfil en `encargados` con el user_id de la
     *   cuenta, y se usa ese perfil aunque no se envíe encargado_id.
     */
    private function validarResponsable(Estudiante $estudiante, $encargadoId, ?int $propietarioId): ?int
    {
        if ($this->padreDeLaCuenta($estudiante)) {
            if ($encargadoId) {
                throw ValidationException::withMessages(['encargado_id' => 'El padre o la madre titular de la cuenta es el responsable del estudiante: no se registra un encargado adicional.']);
            }

            return null;
        }

        $perfil = $estudiante->user_id ? Encargado::where('user_id', $estudiante->user_id)->first() : null;
        if (! $perfil) {
            throw ValidationException::withMessages(['encargado_id' => 'Falta el responsable: la cuenta no está vinculada como padre o madre del estudiante, así que debe registrar su perfil de encargado (Mi perfil) antes de matricular.']);
        }
        if ($encargadoId && (int) $encargadoId !== (int) $perfil->id) {
            throw ValidationException::withMessages(['encargado_id' => 'El encargado seleccionado no es válido para este estudiante.']);
        }

        return $perfil->id;
    }

    /** Responsable que tendría la matrícula del estudiante (para el formulario). */
    public function responsablePara(Estudiante $estudiante): array
    {
        if ($padre = $this->padreDeLaCuenta($estudiante)) {
            return ['tipo' => 'padre', 'id' => $padre->id, 'nombre' => $padre->nombre, 'dui' => $padre->dui, 'parentesco' => $padre->pivot->parentesco, 'falta' => null];
        }
        if ($perfil = $estudiante->user_id ? Encargado::where('user_id', $estudiante->user_id)->first() : null) {
            return ['tipo' => 'encargado', 'id' => $perfil->id, 'nombre' => $perfil->nombre, 'dui' => $perfil->dui, 'parentesco' => $perfil->parentesco, 'falta' => null];
        }

        return ['tipo' => null, 'id' => null, 'nombre' => null, 'dui' => null, 'parentesco' => null,
            'falta' => 'Registre su perfil de encargado en «Mi perfil» o vincúlese como padre o madre del estudiante antes de matricular.'];
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
