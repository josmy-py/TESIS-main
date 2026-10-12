<?php

namespace App\Services;

use App\Models\Encargado;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\Seccion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MatriculaService
{
    /*
     * $propietarioId: id de la cuenta ENCARGADO que consulta; null para el
     * DIRECTOR. Con un propietario, solo se ven los estudiantes (y sus
     * matrículas) registrados por esa cuenta; lo ajeno responde como no
     * encontrado.
     */

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

    // El encargado de una matrícula debe ser el perfil de la propia cuenta
    public function encargadoPermitido($encargadoId, ?int $propietarioId = null): bool
    {
        if ($encargadoId === null || $propietarioId === null) {
            return true;
        }

        return Encargado::where('id', $encargadoId)
            ->where('user_id', $propietarioId)
            ->exists();
    }

    public function listar($estado = null, ?int $propietarioId = null)
    {
        $query = $this->matriculas($propietarioId)->with([
            'estudiante:id,nombre',
            'especialidad:id,nombre',
        ])
            ->select(
                'id',
                'created_at',
                'ingreso',
                'estado',
                'estudiante_id',
                'especialidad_id'
            )
            ->orderBy('id', 'desc');

        if ($estado !== null) {
            $query->where('estado', $estado);
        }

        return $query->get();
    }

    public function crear(array $datos, $foto = null, $documento = null)
    {
        // M2.2: la matrícula pertenece a un año lectivo (el indicado o el activo)
        // y, si trae sección, debe ser del mismo año. Se valida antes de guardar archivos.
        $anioLectivo = $this->calendario()->anioParaMatricula($datos['anio_lectivo_id'] ?? null);

        if (! empty($datos['seccion_id'])) {
            $seccion = Seccion::findOrFail($datos['seccion_id']);
            if ($seccion->anio_lectivo_id !== $anioLectivo->id) {
                throw ValidationException::withMessages([
                    'seccion_id' => 'La sección no pertenece al año lectivo de la matrícula.',
                ]);
            }
        }

        DB::beginTransaction();

        try {
            $rutaF = null;
            $rutaDocumento = null;
            $nombre = null;
            $nombreDocumento = null;

            if ($foto) {
                $nombre = 'foto_'.uniqid().'.'.$foto->getClientOriginalExtension();

                $foto->storeAs('foto', $nombre, 'public');

                $rutaF = '/storage/foto/'.$nombre;
            }

            if ($documento) {
                $nombreDocumento = 'documento_'.uniqid().'.'.$documento->getClientOriginalExtension();

                $documento->storeAs('documentos', $nombreDocumento, 'public');

                $rutaDocumento = '/storage/documentos/'.$nombreDocumento;
            }

            $matricula = Matricula::create([
                'ingreso' => $datos['ingreso'],
                // Campo anterior, conservado durante la transición
                'anio' => $anioLectivo->anioCalendario(),
                'anio_lectivo_id' => $anioLectivo->id,
                'estado' => 'pendiente',
                'foto' => $rutaF,
                'documento' => $rutaDocumento,
                'es_trasladado' => $datos['es_trasladado'],
                'estudiante_id' => $datos['estudiante_id'],
                'enfermedad_id' => $datos['enfermedad_id'],
                'seccion_id' => $datos['seccion_id'] ?? null,
                'especialidad_id' => $datos['especialidad_id'],
                'encargado_id' => $datos['encargado_id'] ?? null,
            ]);

            DB::commit();

            return $matricula;

        } catch (\Exception $e) {
            DB::rollBack();

            if ($nombre && Storage::disk('public')->exists('foto/'.$nombre)) {
                Storage::disk('public')->delete('foto/'.$nombre);
            }

            if ($nombreDocumento && Storage::disk('public')->exists('documentos/'.$nombreDocumento)) {
                Storage::disk('public')->delete('documentos/'.$nombreDocumento);
            }

            throw $e;
        }
    }

    public function obtener($id, ?int $propietarioId = null)
    {
        return $this->matriculas($propietarioId)->with([
            'estudiante',
            'especialidad',
            'seccion',
        ])->findOrFail($id);
    }

    public function actualizar($id, array $datos, ?int $propietarioId = null)
    {
        DB::beginTransaction();

        try {
            $matricula = $this->matriculas($propietarioId)->findOrFail($id);

            $matricula->update([
                'seccion_id' => $datos['seccion_id'] ?? null,
                'enfermedad_id' => $datos['enfermedad_id'],
                'encargado_id' => $datos['encargado_id'],
                'especialidad_id' => $datos['especialidad_id'],
            ]);

            DB::commit();

            return $matricula;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function actualizarEstado($id, $nuevo)
    {
        DB::beginTransaction();

        try {
            $matricula = Matricula::with('estudiante')->findOrFail($id);

            $estadoActual = $matricula->estado;

            $estadosValidos = [
                'pendiente' => ['aprobado', 'rechazado'],
                'aprobado' => [],
                'rechazado' => [],
            ];

            if (! isset($estadosValidos[$estadoActual]) ||
                ! in_array($nuevo, $estadosValidos[$estadoActual])) {
                return [
                    'error' => true,
                    'status' => 400,
                    'message' => "No se puede cambiar el $estadoActual a $nuevo de la matricula",
                ];
            }

            if ($nuevo === 'aprobado') {

                if (! $matricula->seccion_id) {
                    DB::rollBack();

                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'No se puede aprobar la matrícula porque no tiene una sección asignada',
                    ];
                }

                $estudiante = $matricula->estudiante;

                if ($estudiante->estado !== 'inscrito') {
                    DB::rollBack();

                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'El estudiante no esta inscrito',
                    ];
                }

                $estudiante->estado = 'activo';
                $estudiante->update();
            }

            $matricula->estado = $nuevo;
            $matricula->update();

            DB::commit();

            return [
                'error' => false,
                'matricula' => $matricula,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
            ->sortByDesc('anio')
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

    public function validarGrado($estudianteId, $grado, $ingreso, ?int $propietarioId = null)
    {
        // Comprueba la propiedad antes de responder, incluso para nuevo ingreso
        $this->estudiantePropio($estudianteId, $propietarioId);

        if ($ingreso === 'Nuevo ingreso') {
            return [
                'permitido' => true,
                'message' => 'El estudiante es de nuevo ingreso. No requiere validar historial.',
            ];
        }

        $estudiante = Estudiante::with([
            'matriculas.seccion.grado',
            'matriculas.notaFinal',
        ])->findOrFail($estudianteId);

        if ($estudiante->matriculas->isEmpty()) {
            return [
                'permitido' => true,
                'message' => 'El estudiante no tiene historial de matrículas.',
            ];
        }

        $ultimaMatricula = $estudiante->matriculas
            ->sortByDesc('id')
            ->first();

        if (! $ultimaMatricula->seccion) {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'La matrícula anterior no tiene una sección asignada.',
            ];
        }

        $ultimoGrado = $ultimaMatricula->seccion->grado;

        // notaFinal es hasMany (tabla notas_anuales): se toma el registro de cierre más reciente
        $notaAnual = $ultimaMatricula->notaFinal
            ->sortByDesc('fecha_cierre')
            ->first();

        if (! $notaAnual) {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'El estudiante no tiene notas anuales registradas para su matrícula anterior.',
            ];
        }

        if ($notaAnual->estado !== 'Aprobada') {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'El estudiante no aprobó el año anterior. No puede matricular el grado siguiente.',
            ];
        }

        if ($notaAnual->promedio_anual < 7.0) {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'El estudiante no alcanzó el promedio mínimo requerido para aprobar.',
            ];
        }

        // M2.1.1: el grado siguiente se determina por el orden académico, no por el id
        if (! $ultimoGrado || $ultimoGrado->orden === null) {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'No se puede determinar el grado siguiente: el grado anterior no tiene orden académico. El director debe completarlo.',
            ];
        }

        $siguiente = $ultimoGrado->siguiente();

        if (! $siguiente) {
            return [
                'permitido' => false,
                'status' => 422,
                'message' => 'No se puede determinar el grado siguiente: no hay un grado registrado después del grado anterior.',
            ];
        }

        $gradoPermitido = $siguiente->id;

        if ((int) $grado !== $gradoPermitido) {
            return [
                'permitido' => false,
                'grado_permitido' => $gradoPermitido,
                'status' => 422,
                'message' => 'El estudiante aprobó el grado anterior. Solo puede matricular el grado consecutivo.',
            ];
        }

        return [
            'permitido' => true,
            'message' => 'El estudiante aprobó el año anterior y puede matricular el grado siguiente.',
        ];
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

        $cuposDisponibles = $secciones->sum(function ($seccion) {
            return max(
                0,
                $seccion->capacidad - $seccion->matriculas_count
            );
        });

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
        $secciones = $this->seccionesDelAnio($gradoId, $turno, $anioLectivoId);

        return $secciones
            ->map(function ($seccion) {
                $cuposDisponibles = max(
                    0,
                    $seccion->capacidad - $seccion->matriculas_count
                );

                return [
                    'id' => $seccion->id,
                    'nombre' => $seccion->nombre,
                    'capacidad' => $seccion->capacidad,
                    'cupos_disponibles' => $cuposDisponibles,
                ];
            })
            ->filter(fn ($seccion) => $seccion['cupos_disponibles'] > 0)
            ->values();
    }

    public function verificarDuplicidad($estudianteId, $anio, $anioLectivoId = null)
    {
        return Matricula::where('estudiante_id', $estudianteId)
            ->when(
                $anioLectivoId,
                fn ($query) => $query->where('anio_lectivo_id', $anioLectivoId),
                fn ($query) => $query->where('anio', $anio)
            )
            ->whereIn('estado', ['pendiente', 'aprobado'])
            ->exists();
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
                        ->whereIn('estado', ['pendiente', 'aprobado']);
                },
            ])
            ->get();
    }

    private function calendario(): CalendarioService
    {
        return app(CalendarioService::class);
    }
}
