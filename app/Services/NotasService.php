<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Calificacion;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\PeriodoEscolar;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M7 Notas, según el diagrama de clases:
 * - Profesor.obtenerEstudiantes(asignacion): matrículas aprobadas de la sección.
 * - Evaluacion(nombre, porcentaje, periodoEscolar, asignacion): crear, eliminar
 *   y validarDisponibilidad (la suma de porcentajes del periodo no pasa de 100).
 * - Calificacion(nota_obtenida, evaluacion, matricula): registrar y actualizar.
 *
 * Cada docente trabaja solo con sus asignaciones (lo ajeno responde 404). Las
 * notas se registran o modifican solo en periodos de un año lectivo activo.
 */
class NotasService
{
    public const NOTA_MAXIMA = 10;
    public const PORCENTAJE_TOTAL = 100;

    /** Asignación del docente en sesión, con su sección y materia. */
    public function asignacionPropia(User $docente, $asignacionId): Asignacion
    {
        return Asignacion::with(['seccion.grado:id,nombre', 'seccion.anioLectivo', 'asignatura'])
            ->where('profesor_id', $docente->profesor?->id ?? 0)
            ->findOrFail($asignacionId);
    }

    /** Profesor.obtenerEstudiantes(asignacion): matrículas aprobadas de la sección, por nombre. */
    public function obtenerEstudiantes(Asignacion $asignacion): Collection
    {
        return Matricula::with('estudiante:id,nombre,nie,genero')
            ->where('seccion_id', $asignacion->seccion_id)
            ->where('estado', 'aprobado')
            ->get(['id', 'estudiante_id', 'seccion_id', 'estado'])
            ->sortBy(fn (Matricula $m) => mb_strtolower((string) $m->estudiante?->nombre))
            ->values();
    }

    /**
     * Ficha del estudiante para el docente (Mis estudiantes > Ver, pedido de Josmy del
     * 23/10/2026): datos del estudiante, padres (con DUI), encargado de la matrícula y
     * si proviene de otra institución. Solo matrículas aprobadas de la sección.
     */
    public function fichaEstudiante(Asignacion $asignacion, $matriculaId): array
    {
        $matricula = Matricula::with([
            'estudiante:id,nombre,nie,genero,fecha_nacimiento',
            'estudiante.padres',
            'encargado:id,nombre,dui,parentesco,telefono',
        ])->where('seccion_id', $asignacion->seccion_id)->where('estado', 'aprobado')->findOrFail($matriculaId);
        $estudiante = $matricula->estudiante;

        return [
            'matricula_id' => $matricula->id,
            'es_trasladado' => (bool) $matricula->es_trasladado,
            'estudiante' => [
                'nombre' => $estudiante?->nombre,
                'nie' => $estudiante?->nie,
                'genero' => $estudiante?->genero,
                'fecha_nacimiento' => $estudiante?->fecha_nacimiento ? Carbon::parse($estudiante->fecha_nacimiento)->format('Y-m-d') : null,
            ],
            'padres' => ($estudiante?->padres ?? collect())->map(fn ($p) => [
                'nombre' => $p->nombre, 'parentesco' => $p->pivot?->parentesco, 'dui' => $p->dui,
                'telefono' => $p->telefono, 'email' => $p->email,
            ])->values(),
            'encargado' => $matricula->encargado?->only(['nombre', 'dui', 'parentesco', 'telefono']),
        ];
    }

    /** Periodos del año lectivo de la sección, de su nivel académico. */
    public function periodos(Asignacion $asignacion): Collection
    {
        return PeriodoEscolar::deLaSeccion($asignacion->seccion)
            ->get(['id', 'nombre', 'nivel', 'anio_lectivo_id']);
    }

    /** Evaluaciones de la asignación en un periodo, con su avance de calificaciones. */
    public function evaluaciones(Asignacion $asignacion, $periodoId): array
    {
        $periodo = $this->periodoDeLaSeccion($asignacion, $periodoId);
        $evaluaciones = Evaluacion::where('asignacion_id', $asignacion->id)
            ->where('periodo_escolar_id', $periodo->id)
            ->withCount('calificaciones')
            ->orderBy('id')
            ->get();

        return [
            'periodo' => $periodo,
            'evaluaciones' => $evaluaciones,
            'porcentaje_usado' => $this->redondear($evaluaciones->sum('porcentaje')),
            'porcentaje_disponible' => $this->redondear(self::PORCENTAJE_TOTAL - $evaluaciones->sum('porcentaje')),
            'editable' => $periodo->permiteRegistros(),
        ];
    }

    /** Evaluacion.crear(), después de validarDisponibilidad(). */
    public function crearEvaluacion(Asignacion $asignacion, array $datos): Evaluacion
    {
        return DB::transaction(function () use ($asignacion, $datos) {
            $periodo = $this->periodoEditable($asignacion, $datos['periodo_escolar_id']);
            // Bloquea las evaluaciones del periodo para que dos altas simultáneas no pasen de 100
            Evaluacion::where('asignacion_id', $asignacion->id)->where('periodo_escolar_id', $periodo->id)->lockForUpdate()->get();
            $this->validarDisponibilidad($asignacion, $periodo, (float) $datos['porcentaje']);

            return Evaluacion::create([
                'nombre' => $datos['nombre'],
                'porcentaje' => $datos['porcentaje'],
                'periodo_escolar_id' => $periodo->id,
                'asignacion_id' => $asignacion->id,
            ]);
        });
    }

    /** Evaluacion.validarDisponibilidad(): el porcentaje cabe en lo que queda del periodo. */
    public function validarDisponibilidad(Asignacion $asignacion, PeriodoEscolar $periodo, float $porcentaje): void
    {
        $usado = (float) Evaluacion::where('asignacion_id', $asignacion->id)->where('periodo_escolar_id', $periodo->id)->sum('porcentaje');
        $disponible = $this->redondear(self::PORCENTAJE_TOTAL - $usado);

        if ($porcentaje > $disponible + 0.001) {
            throw ValidationException::withMessages(['porcentaje' => $disponible <= 0
                ? "Las evaluaciones de {$periodo->nombre} ya suman 100 %."
                : "Solo queda {$this->texto($disponible)} % disponible en {$periodo->nombre}."]);
        }
    }

    /** Evaluacion.eliminar(): solo sin notas registradas, para no perderlas. */
    public function eliminarEvaluacion(User $docente, $evaluacionId): void
    {
        DB::transaction(function () use ($docente, $evaluacionId) {
            $evaluacion = $this->evaluacionPropia($docente, $evaluacionId, true);
            $this->asegurarEditable($evaluacion->periodo);

            if ($evaluacion->calificaciones()->exists()) {
                throw new \DomainException("La evaluación {$evaluacion->nombre} ya tiene notas registradas: no se puede eliminar.");
            }

            $evaluacion->delete();
        });
    }

    /** Notas de una evaluación: cada estudiante de la sección con su nota (o null). */
    public function calificaciones(User $docente, $evaluacionId): array
    {
        $evaluacion = $this->evaluacionPropia($docente, $evaluacionId);
        $notas = Calificacion::where('evaluacion_id', $evaluacion->id)->pluck('nota_obtenida', 'matricula_id');

        return [
            'evaluacion' => $evaluacion->load('periodo:id,nombre,nivel,anio_lectivo_id'),
            'editable' => $evaluacion->periodo->permiteRegistros(),
            'estudiantes' => $this->obtenerEstudiantes($evaluacion->asignacion)->map(fn (Matricula $m) => [
                'matricula_id' => $m->id,
                'estudiante' => $m->estudiante,
                'nota' => isset($notas[$m->id]) ? (float) $notas[$m->id] : null,
            ])->values(),
        ];
    }

    /**
     * Calificacion.registrar() para quien aún no tiene nota y
     * Calificacion.actualizar(nota) para quien ya la tiene.
     *
     * @param  array<int, array{matricula_id: int, nota: float|null}>  $notas
     */
    public function guardarCalificaciones(User $docente, $evaluacionId, array $notas): array
    {
        return DB::transaction(function () use ($docente, $evaluacionId, $notas) {
            $evaluacion = $this->evaluacionPropia($docente, $evaluacionId, true);
            $this->asegurarEditable($evaluacion->periodo);

            $validas = $this->obtenerEstudiantes($evaluacion->asignacion)->pluck('id')->all();
            $ajenas = collect($notas)->pluck('matricula_id')->reject(fn ($id) => in_array((int) $id, $validas, true));
            if ($ajenas->isNotEmpty()) {
                throw ValidationException::withMessages(['notas' => 'Hay estudiantes que no están matriculados en la sección de esta materia.']);
            }

            $registradas = 0;
            $actualizadas = 0;
            foreach ($notas as $fila) {
                if ($fila['nota'] === null || $fila['nota'] === '') {
                    continue;
                }
                $calificacion = Calificacion::where('evaluacion_id', $evaluacion->id)
                    ->where('matricula_id', $fila['matricula_id'])->lockForUpdate()->first();

                if ($calificacion) {
                    if ((float) $calificacion->nota_obtenida !== (float) $fila['nota']) {
                        $calificacion->update(['nota_obtenida' => $fila['nota']]);
                        $actualizadas++;
                    }
                } else {
                    Calificacion::create(['nota_obtenida' => $fila['nota'], 'evaluacion_id' => $evaluacion->id, 'matricula_id' => $fila['matricula_id']]);
                    $registradas++;
                }
            }

            // Si la libreta de la materia ya se generó, sus boletas siguen a las notas (M9)
            if ($registradas || $actualizadas) {
                app(LibretaService::class)->actualizarBoletas($evaluacion);
            }

            return ['registradas' => $registradas, 'actualizadas' => $actualizadas];
        });
    }

    // --- Internos ---

    private function evaluacionPropia(User $docente, $evaluacionId, bool $bloquear = false): Evaluacion
    {
        return Evaluacion::with(['asignacion.seccion', 'periodo.anioLectivo'])
            ->whereHas('asignacion', fn ($q) => $q->where('profesor_id', $docente->profesor?->id ?? 0))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->findOrFail($evaluacionId);
    }

    private function periodoDeLaSeccion(Asignacion $asignacion, $periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::with('anioLectivo')->find($periodoId);
        if (! $periodo || ! $periodo->esDeLaSeccion($asignacion->seccion)) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El periodo no pertenece al año lectivo ni al nivel de la sección.']);
        }

        return $periodo;
    }

    private function periodoEditable(Asignacion $asignacion, $periodoId): PeriodoEscolar
    {
        $periodo = $this->periodoDeLaSeccion($asignacion, $periodoId);
        $this->asegurarEditable($periodo);

        return $periodo;
    }

    private function asegurarEditable(PeriodoEscolar $periodo): void
    {
        if (! $periodo->permiteRegistros()) {
            throw new \DomainException($periodo->motivoBloqueo('no se pueden registrar ni modificar notas'));
        }
    }

    private function redondear(float $valor): float
    {
        return round($valor, 2);
    }

    private function texto(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }
}
