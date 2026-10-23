<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Conducta;
use App\Models\Matricula;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M8 Conducta, según el diagrama de clases: Conducta(descripcion, valoracion,
 * periodoEscolar, matricula) con registrar() y actualizar(descripcion, valoracion).
 * Mapa de funciones: SIGA Docente > Conducta > "Registrar observaciones" y
 * "Seguimiento conductual".
 *
 * Una conducta por estudiante y periodo. La registran los docentes de la sección
 * (con una materia asignada en ella o como docente guía); lo ajeno responde 404.
 * Solo se registra o modifica en periodos de un año lectivo activo.
 */
class ConductaService
{
    public const VALORACIONES = ['Excelente', 'Bueno', 'Malo', 'Deficiente'];

    /**
     * Observación fija de cada valoración (pedido de Josmy del 23/10/2026): el
     * docente solo elige la valoración y su texto se guarda en conductas.descripcion.
     */
    public const OBSERVACIONES = [
        'Excelente' => 'Muestra un comportamiento ejemplar y respeta las normas de convivencia.',
        'Bueno' => 'Mantiene un buen comportamiento y cumple las normas de convivencia.',
        'Malo' => 'Presenta faltas a las normas de convivencia; debe mejorar su comportamiento.',
        'Deficiente' => 'Incumple de forma reiterada las normas de convivencia; requiere seguimiento.',
    ];

    /** Sección en la que el docente imparte una materia o es docente guía. */
    public function seccionPropia(User $docente, $seccionId): Seccion
    {
        $profesorId = $docente->profesor?->id ?? 0;

        return Seccion::with(['grado:id,nombre', 'anioLectivo'])
            ->where(fn ($q) => $q->where('profesor_id', $profesorId)
                ->orWhereHas('asignaciones', fn ($a) => $a->where('profesor_id', $profesorId)))
            ->findOrFail($seccionId);
    }

    /** Estudiantes de la sección con su conducta del periodo (o null). */
    public function listar(Seccion $seccion, $periodoId = null): array
    {
        $periodos = PeriodoEscolar::deLaSeccion($seccion)->get(['id', 'nombre', 'nivel', 'estado', 'anio_lectivo_id']);
        $periodo = $periodoId ? $this->periodoDeLaSeccion($seccion, $periodoId) : $periodos->first()?->load('anioLectivo');

        $conductas = $periodo
            ? Conducta::where('periodo_escolar_id', $periodo->id)->get()->keyBy('matricula_id')
            : collect();

        return [
            'seccion' => $seccion,
            'periodos' => $periodos,
            'periodo' => $periodo,
            'editable' => (bool) $periodo?->permiteRegistros(),
            'valoraciones' => self::VALORACIONES,
            'observaciones' => self::OBSERVACIONES,
            'estudiantes' => $this->matriculas($seccion)->map(fn (Matricula $m) => [
                'matricula_id' => $m->id,
                'estudiante' => $m->estudiante,
                'conducta' => $conductas->get($m->id)?->only(['id', 'descripcion', 'valoracion']),
            ])->values(),
        ];
    }

    /**
     * Conducta.registrar() para quien aún no la tiene en el periodo y
     * Conducta.actualizar(descripcion, valoracion) para quien ya la tiene.
     *
     * La descripción es siempre la observación fija de la valoración.
     *
     * @param  array<int, array{matricula_id: int, valoracion: string}>  $filas
     */
    public function guardar(Seccion $seccion, $periodoId, array $filas): array
    {
        return DB::transaction(function () use ($seccion, $periodoId, $filas) {
            $periodo = $this->periodoDeLaSeccion($seccion, $periodoId);
            if (! $periodo->permiteRegistros()) {
                throw new \DomainException($periodo->motivoBloqueo('no se puede registrar ni modificar la conducta'));
            }

            $validas = $this->matriculas($seccion)->pluck('id')->all();
            if (collect($filas)->pluck('matricula_id')->contains(fn ($id) => ! in_array((int) $id, $validas, true))) {
                throw ValidationException::withMessages(['conductas' => 'Hay estudiantes que no están matriculados en esta sección.']);
            }

            $registradas = 0;
            $actualizadas = 0;
            foreach ($filas as $fila) {
                $fila['descripcion'] = self::OBSERVACIONES[$fila['valoracion']];
                $conducta = Conducta::where('periodo_escolar_id', $periodo->id)
                    ->where('matricula_id', $fila['matricula_id'])->lockForUpdate()->first();

                if (! $conducta) {
                    Conducta::create([
                        'descripcion' => $fila['descripcion'], 'valoracion' => $fila['valoracion'],
                        'matricula_id' => $fila['matricula_id'], 'periodo_escolar_id' => $periodo->id,
                    ]);
                    $registradas++;
                } elseif ($conducta->descripcion !== $fila['descripcion'] || $conducta->valoracion !== $fila['valoracion']) {
                    $conducta->update(['descripcion' => $fila['descripcion'], 'valoracion' => $fila['valoracion']]);
                    $actualizadas++;
                }
            }

            return ['registradas' => $registradas, 'actualizadas' => $actualizadas];
        });
    }

    /** Matrículas aprobadas de la sección, por nombre del estudiante. */
    public function matriculas(Seccion $seccion): Collection
    {
        return Matricula::with('estudiante:id,nombre,nie,genero')
            ->where('seccion_id', $seccion->id)
            ->where('estado', 'aprobado')
            ->get(['id', 'estudiante_id', 'seccion_id', 'estado'])
            ->sortBy(fn (Matricula $m) => mb_strtolower((string) $m->estudiante?->nombre))
            ->values();
    }

    private function periodoDeLaSeccion(Seccion $seccion, $periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::with('anioLectivo')->find($periodoId);
        if (! $periodo || ! $periodo->esDeLaSeccion($seccion)) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El periodo no pertenece al año lectivo ni al nivel de la sección.']);
        }

        return $periodo;
    }
}
