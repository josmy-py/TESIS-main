<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\Conducta;
use App\Models\Matricula;
use App\Models\NotaFinal;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * M12 Reportes del diagrama:
 * - Docente > Libreta > "Reporte de notas" y "Reporte de conducta" (de sus secciones;
 *   en el de notas, solo sus materias).
 * - Director > "Generar reportes académicos" (cualquier sección, todas las materias).
 * El promedio de cada materia es el de su boleta (M9); sin boleta queda vacío.
 */
class ReporteService
{
    public function __construct(private ConductaService $conducta)
    {
    }

    /** Sección para el reporte: el director cualquiera; el docente, una donde enseña o es guía. */
    public function seccion(User $user, $seccionId): Seccion
    {
        return $user->esDirector()
            ? Seccion::with(['grado:id,nombre', 'anioLectivo:id,nombre,estado'])->findOrFail($seccionId)
            : $this->conducta->seccionPropia($user, $seccionId);
    }

    public function notas(User $user, Seccion $seccion, $periodoId): array
    {
        $periodo = $this->periodo($seccion, $periodoId);
        $asignaciones = Asignacion::with('asignatura:id,nombre')->where('seccion_id', $seccion->id)
            ->when(! $user->esDirector(), fn ($q) => $q->where('profesor_id', $user->profesor?->id ?? 0))
            ->get()->sortBy(fn ($a) => mb_strtolower((string) $a->asignatura?->nombre))->values();
        if (! $user->esDirector() && $asignaciones->isEmpty()) {
            throw new ModelNotFoundException();
        }

        $matriculas = $this->conducta->matriculas($seccion);
        $boletas = Boleta::where('periodo_escolar_id', $periodo->id)->whereIn('asignacion_id', $asignaciones->pluck('id'))
            ->get()->groupBy('matricula_id');
        $finales = NotaFinal::whereIn('matricula_id', $matriculas->pluck('id'))->get()->keyBy('matricula_id');

        return [
            'seccion' => $seccion,
            'periodo' => $periodo,
            'materias' => $asignaciones->map(fn ($a) => ['asignacion_id' => $a->id, 'nombre' => $a->asignatura?->nombre])->values(),
            'estudiantes' => $matriculas->map(function (Matricula $m) use ($asignaciones, $boletas, $finales) {
                $propias = ($boletas[$m->id] ?? collect())->keyBy('asignacion_id');
                $promedios = $asignaciones->mapWithKeys(fn ($a) => [$a->id => isset($propias[$a->id]) ? (float) $propias[$a->id]->promedio : null]);
                $conNota = $promedios->filter(fn ($p) => $p !== null);

                return [
                    'matricula_id' => $m->id,
                    'estudiante' => $m->estudiante,
                    'promedios' => $promedios,
                    'promedio' => $conNota->isEmpty() ? null : round($conNota->avg(), 2),
                    'nota_final' => $finales->get($m->id)?->only(['promedio_anual', 'estado']),
                ];
            })->values(),
        ];
    }

    public function conducta(Seccion $seccion, $periodoId): array
    {
        $periodo = $this->periodo($seccion, $periodoId);
        $conductas = Conducta::where('periodo_escolar_id', $periodo->id)->get()->keyBy('matricula_id');

        return [
            'seccion' => $seccion,
            'periodo' => $periodo,
            'estudiantes' => $this->conducta->matriculas($seccion)->map(fn (Matricula $m) => [
                'matricula_id' => $m->id,
                'estudiante' => $m->estudiante,
                'conducta' => $conductas->get($m->id)?->only(['descripcion', 'valoracion']),
            ])->values(),
        ];
    }

    private function periodo(Seccion $seccion, $periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::find($periodoId);
        if (! $periodo || (int) $periodo->anio_lectivo_id !== (int) $seccion->anio_lectivo_id) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El periodo no pertenece al año lectivo de la sección.']);
        }

        return $periodo;
    }
}
