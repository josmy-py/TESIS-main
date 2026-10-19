<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\Calificacion;
use App\Models\Conducta;
use App\Models\Estudiante;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\NotaFinal;
use App\Models\PeriodoEscolar;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Consultas del diagrama sobre una matrícula:
 * - M10 Encargado > Consultar estudiante > "Consultar notas" (Matricula.obtenerNotas())
 *   y "Consultar conducta".
 * - M11 Director > Registrar estudiante > "Consultar expediente", y
 *   Matricula.generarComprobante().
 *
 * El encargado solo consulta a sus estudiantes (lo ajeno responde 404).
 * Las notas y la conducta son las de matrículas aprobadas.
 */
class ConsultaAcademicaService
{
    public function __construct(private EstudianteService $estudiantes)
    {
    }

    /** Matrícula aprobada pedida, o la del año más reciente; null si no tiene. */
    public function matriculaDe(Estudiante $estudiante, $matriculaId = null): array
    {
        $aprobadas = Matricula::with(['seccion:id,nombre,turno,grado_id,anio_lectivo_id', 'seccion.grado:id,nombre', 'anioLectivo:id,anio,estado,fecha_inicio'])
            ->where('estudiante_id', $estudiante->id)
            ->where('estado', 'aprobado')
            ->whereNotNull('seccion_id')
            ->get(['id', 'estudiante_id', 'seccion_id', 'anio_lectivo_id', 'estado', 'especialidad_id'])
            ->sortByDesc(fn (Matricula $m) => [(string) $m->anioLectivo?->fecha_inicio, $m->id])
            ->values();

        $matricula = $matriculaId ? $aprobadas->firstWhere('id', (int) $matriculaId) : $aprobadas->first();
        if ($matriculaId && ! $matricula) {
            throw new ModelNotFoundException();
        }

        return [
            'estudiante' => $estudiante->only(['id', 'nombre', 'nie']),
            'matriculas' => $aprobadas->map(fn (Matricula $m) => [
                'id' => $m->id,
                'anio' => $m->anioLectivo?->anio,
                'grado' => $m->seccion?->grado?->nombre,
                'seccion' => $m->seccion?->nombre,
            ])->values(),
            'matricula' => $matricula,
        ];
    }

    /** Matricula.obtenerNotas(): cada materia con sus evaluaciones, notas y promedio por periodo. */
    /** Asignaturas del estudiante: las materias asignadas a la sección de su matrícula, con su docente. */
    public function obtenerAsignaturas(Matricula $matricula): array
    {
        return [
            'asignaturas' => Asignacion::with(['asignatura:id,nombre', 'profesor:id,nombre'])
                ->where('seccion_id', $matricula->seccion_id)->get()
                ->map(fn (Asignacion $a) => ['asignacion_id' => $a->id, 'asignatura' => $a->asignatura?->nombre, 'docente' => $a->profesor?->nombre])
                ->sortBy(fn ($a) => mb_strtolower((string) $a['asignatura']))->values(),
        ];
    }

    public function obtenerNotas(Matricula $matricula): array
    {
        $periodos = $this->periodos($matricula);
        $asignaciones = Asignacion::with(['asignatura:id,nombre', 'profesor:id,nombre'])
            ->where('seccion_id', $matricula->seccion_id)->get()
            ->sortBy(fn ($a) => mb_strtolower((string) $a->asignatura?->nombre))->values();
        $evaluaciones = Evaluacion::whereIn('asignacion_id', $asignaciones->pluck('id'))->orderBy('id')->get()->groupBy(['asignacion_id', 'periodo_escolar_id']);
        $notas = Calificacion::where('matricula_id', $matricula->id)->pluck('nota_obtenida', 'evaluacion_id');
        $boletas = Boleta::where('matricula_id', $matricula->id)->get()->keyBy(fn ($b) => "{$b->asignacion_id}-{$b->periodo_escolar_id}");

        return [
            'periodos' => $periodos,
            'materias' => $asignaciones->map(fn (Asignacion $a) => [
                'asignacion_id' => $a->id,
                'asignatura' => $a->asignatura?->nombre,
                'docente' => $a->profesor?->nombre,
                'periodos' => $periodos->map(fn (PeriodoEscolar $p) => [
                    'periodo_escolar_id' => $p->id,
                    'evaluaciones' => collect($evaluaciones[$a->id][$p->id] ?? [])->map(fn (Evaluacion $e) => [
                        'nombre' => $e->nombre,
                        'porcentaje' => (float) $e->porcentaje,
                        'nota' => isset($notas[$e->id]) ? (float) $notas[$e->id] : null,
                    ])->values(),
                    'promedio' => isset($boletas["{$a->id}-{$p->id}"]) ? (float) $boletas["{$a->id}-{$p->id}"]->promedio : null,
                ])->values(),
            ])->values(),
            'nota_final' => $this->notaFinal($matricula),
        ];
    }

    /** Conducta de la matrícula en cada periodo del año (null si aún no se registra). */
    public function conducta(Matricula $matricula): array
    {
        $conductas = Conducta::where('matricula_id', $matricula->id)->get()->keyBy('periodo_escolar_id');

        return [
            'periodos' => $this->periodos($matricula)->map(fn (PeriodoEscolar $p) => [
                'periodo' => $p,
                'conducta' => $conductas->get($p->id)?->only(['descripcion', 'valoracion']),
            ])->values(),
        ];
    }

    /** Consultar expediente: datos del estudiante, su familia y cada matrícula con su resultado. */
    public function expediente($estudianteId): array
    {
        $datos = $this->estudiantes->obtener($estudianteId);
        $matriculas = $datos['estudiante']->matriculas;
        $finales = NotaFinal::whereIn('matricula_id', $matriculas->pluck('id'))->get()->keyBy('matricula_id');
        $conductas = Conducta::with('periodo:id,nombre_periodo,nivel')->whereIn('matricula_id', $matriculas->pluck('id'))->get()->groupBy('matricula_id');

        return [
            'estudiante' => $datos['estudiante'],
            'resultados' => $matriculas->map(fn (Matricula $m) => [
                'matricula_id' => $m->id,
                'nota_final' => $finales->get($m->id)?->only(['promedio_anual', 'estado']),
                'conducta' => ($conductas[$m->id] ?? collect())->sortBy('periodo.nivel')->map(fn ($c) => [
                    'periodo' => $c->periodo?->nombre_periodo, 'descripcion' => $c->descripcion, 'valoracion' => $c->valoracion,
                ])->values(),
            ])->values(),
        ];
    }

    /** Matricula.generarComprobante(): código y datos del comprobante de matrícula. */
    public function generarComprobante($matriculaId, ?int $propietarioId = null): array
    {
        $matricula = Matricula::with([
            'estudiante:id,nombre,nie,user_id', 'seccion:id,nombre,turno,grado_id', 'seccion.grado:id,nombre',
            'anioLectivo:id,anio', 'especialidad:id,nombre', 'encargado:id,nombre,parentesco',
        ])
            ->when($propietarioId, fn ($q) => $q->whereHas('estudiante', fn ($e) => $e->where('user_id', $propietarioId)))
            ->findOrFail($matriculaId);

        $anio = $matricula->anioLectivo?->anio ?? $matricula->created_at?->format('Y');

        return [
            'comprobante' => sprintf('MAT-%s-%06d', $anio ?? '0000', $matricula->id),
            'fecha' => $matricula->created_at?->toDateString(),
            'estado' => $matricula->estado,
            'ingreso' => $matricula->ingreso,
            'es_trasladado' => $matricula->es_trasladado,
            'anio' => $anio,
            'estudiante' => $matricula->estudiante?->only(['nombre', 'nie']),
            'grado' => $matricula->seccion?->grado?->nombre,
            'seccion' => $matricula->seccion?->nombre,
            'turno' => $matricula->seccion?->turno,
            'especialidad' => $matricula->especialidad?->nombre,
            'encargado' => $matricula->encargado?->only(['nombre', 'parentesco']),
        ];
    }

    private function periodos(Matricula $matricula): Collection
    {
        return PeriodoEscolar::where('anio_lectivo_id', $matricula->seccion?->anio_lectivo_id ?? 0)
            ->orderBy('nivel')->get(['id', 'nombre_periodo', 'nivel']);
    }

    private function notaFinal(Matricula $matricula): ?array
    {
        $nota = $matricula->notaFinal()->latest('id')->first();

        return $nota ? ['promedio_anual' => (float) $nota->promedio_anual, 'estado' => $nota->estado] : null;
    }
}
