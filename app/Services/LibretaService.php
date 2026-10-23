<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\Calificacion;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\NotaFinal;
use App\Models\PeriodoEscolar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M9 Libreta, según el diagrama de clases:
 * - Boleta(promedio, matricula, periodoEscolar, asignacion): promedio de una
 *   materia en un periodo = suma de nota × porcentaje de sus evaluaciones.
 *   El docente la genera cuando las evaluaciones del periodo suman 100 % y
 *   todos sus estudiantes tienen nota; si después cambia una nota, la boleta
 *   se actualiza sola.
 * - Nota_final(promedio_anual, estado A/R, matricula): calcularPromedioAnual()
 *   promedia las boletas del año cuando están todas (cada materia de la sección
 *   en cada periodo) y determinarEstado() aprueba con PromocionService::NOTA_MINIMA.
 */
class LibretaService
{
    public const APROBADA = 'Aprobada';
    public const REPROBADA = 'Reprobada';

    public function __construct(private NotasService $notas)
    {
    }

    /** Estado de la libreta de una materia en un periodo: lo calculado y lo guardado. */
    public function estado(Asignacion $asignacion, $periodoId): array
    {
        $periodo = $this->periodoDeLaSeccion($asignacion, $periodoId);
        $calculo = $this->calcular($asignacion, $periodo);
        $boletas = Boleta::where('asignacion_id', $asignacion->id)->where('periodo_escolar_id', $periodo->id)->pluck('promedio', 'matricula_id');

        return [
            'periodo' => $periodo,
            'editable' => $periodo->permiteRegistros(),
            'porcentaje_usado' => $calculo['porcentaje'],
            'completa' => $calculo['error'] === null,
            'motivo' => $calculo['error'],
            'estudiantes' => $calculo['filas']->map(fn ($f) => [
                'matricula_id' => $f['matricula']->id,
                'estudiante' => $f['matricula']->estudiante,
                'promedio' => $f['promedio'],
                'faltan' => $f['faltan'],
                'boleta' => isset($boletas[$f['matricula']->id]) ? (float) $boletas[$f['matricula']->id] : null,
            ])->values(),
        ];
    }

    /** Boleta.crear() / actualizar() de toda la sección para la materia y el periodo. */
    public function generar(Asignacion $asignacion, $periodoId): array
    {
        return DB::transaction(function () use ($asignacion, $periodoId) {
            $periodo = $this->periodoDeLaSeccion($asignacion, $periodoId);
            if (! $periodo->permiteRegistros()) {
                throw new \DomainException($periodo->motivoBloqueo('no se puede generar la libreta'));
            }

            $calculo = $this->calcular($asignacion, $periodo);
            if ($calculo['error']) {
                throw ValidationException::withMessages(['libreta' => $calculo['error']]);
            }

            $creadas = 0;
            $actualizadas = 0;
            foreach ($calculo['filas'] as $fila) {
                $boleta = $this->guardarBoleta($asignacion, $periodo, $fila['matricula'], $fila['promedio']);
                $boleta->wasRecentlyCreated ? $creadas++ : ($boleta->wasChanged('promedio') ? $actualizadas++ : null);
                $this->calcularNotaFinal($fila['matricula']);
            }

            return ['creadas' => $creadas, 'actualizadas' => $actualizadas];
        });
    }

    /** Tras cambiar notas: actualiza las boletas ya generadas de la materia y el periodo. */
    public function actualizarBoletas(Evaluacion $evaluacion): void
    {
        $existentes = Boleta::where('asignacion_id', $evaluacion->asignacion_id)
            ->where('periodo_escolar_id', $evaluacion->periodo_escolar_id)->pluck('matricula_id')->all();
        if (! $existentes) {
            return;
        }

        $calculo = $this->calcular($evaluacion->asignacion, $evaluacion->periodo);
        foreach ($calculo['filas'] as $fila) {
            if ($fila['promedio'] !== null && in_array($fila['matricula']->id, $existentes, true)) {
                $this->guardarBoleta($evaluacion->asignacion, $evaluacion->periodo, $fila['matricula'], $fila['promedio']);
                $this->calcularNotaFinal($fila['matricula']);
            }
        }
    }

    /**
     * Nota_final.calcularPromedioAnual() y determinarEstado(). Sin todas las
     * boletas del año la nota final no existe (se quita si había una).
     */
    public function calcularNotaFinal(Matricula $matricula): ?NotaFinal
    {
        $seccion = $matricula->seccion;
        $asignaciones = $seccion ? Asignacion::where('seccion_id', $seccion->id)->pluck('id') : collect();
        $periodos = $seccion ? PeriodoEscolar::deLaSeccion($seccion)->pluck('id') : collect();
        $boletas = Boleta::where('matricula_id', $matricula->id)
            ->whereIn('asignacion_id', $asignaciones)->whereIn('periodo_escolar_id', $periodos)->get();

        $esperadas = $asignaciones->count() * $periodos->count();
        if ($esperadas === 0 || $boletas->count() < $esperadas) {
            NotaFinal::where('matricula_id', $matricula->id)->delete();

            return null;
        }

        $promedio = round((float) $boletas->avg('promedio'), 2);

        return NotaFinal::updateOrCreate(['matricula_id' => $matricula->id], [
            'promedio_anual' => $promedio,
            'estado' => $promedio >= PromocionService::NOTA_MINIMA ? self::APROBADA : self::REPROBADA,
        ]);
    }

    // --- Internos ---

    /**
     * Promedio de cada estudiante en la materia y el periodo.
     *
     * @return array{porcentaje: float, error: ?string, filas: Collection}
     */
    private function calcular(Asignacion $asignacion, PeriodoEscolar $periodo): array
    {
        $evaluaciones = Evaluacion::where('asignacion_id', $asignacion->id)->where('periodo_escolar_id', $periodo->id)->get();
        $porcentaje = round((float) $evaluaciones->sum('porcentaje'), 2);
        $notas = Calificacion::whereIn('evaluacion_id', $evaluaciones->pluck('id'))->get()->groupBy('matricula_id');

        $filas = $this->notas->obtenerEstudiantes($asignacion)->map(function (Matricula $m) use ($evaluaciones, $notas) {
            $propias = ($notas[$m->id] ?? collect())->keyBy('evaluacion_id');
            $faltan = $evaluaciones->reject(fn ($e) => $propias->has($e->id))->count();
            $promedio = $faltan || $evaluaciones->isEmpty() ? null : round($evaluaciones->sum(
                fn ($e) => (float) $propias[$e->id]->nota_obtenida * (float) $e->porcentaje / 100
            ), 2);

            return ['matricula' => $m, 'promedio' => $promedio, 'faltan' => $faltan];
        });

        $sinNota = $filas->filter(fn ($f) => $f['faltan'] > 0);
        $error = match (true) {
            abs($porcentaje - NotasService::PORCENTAJE_TOTAL) > 0.001 => "Las evaluaciones de {$periodo->nombre} suman {$porcentaje} %: deben sumar 100 % para generar la libreta.",
            $filas->isEmpty() => 'La sección no tiene estudiantes con matrícula aprobada.',
            $sinNota->isNotEmpty() => 'Faltan notas de '.$sinNota->count().' estudiante(s): '.$sinNota->take(5)->map(fn ($f) => $f['matricula']->estudiante?->nombre)->implode(', ').($sinNota->count() > 5 ? '…' : '').'.',
            default => null,
        };

        return ['porcentaje' => $porcentaje, 'error' => $error, 'filas' => $filas];
    }

    private function guardarBoleta(Asignacion $asignacion, PeriodoEscolar $periodo, Matricula $matricula, float $promedio): Boleta
    {
        return Boleta::updateOrCreate(
            ['matricula_id' => $matricula->id, 'asignacion_id' => $asignacion->id, 'periodo_escolar_id' => $periodo->id],
            ['promedio' => $promedio]
        );
    }

    private function periodoDeLaSeccion(Asignacion $asignacion, $periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::with('anioLectivo')->find($periodoId);
        if (! $periodo || ! $periodo->esDeLaSeccion($asignacion->seccion)) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El periodo no pertenece al año lectivo ni al nivel de la sección.']);
        }

        return $periodo;
    }
}
