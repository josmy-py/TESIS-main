<?php

namespace App\Services;

use App\Models\AnioLectivo;
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
 * La registran los docentes de la sección (con una materia asignada en ella o
 * como docente guía); lo ajeno responde 404.
 *
 * Pedido de Josmy del 23/10/2026: la conducta se evalúa con 5 criterios fijos,
 * cada uno con su valoración (una fila de conductas por criterio: descripcion =
 * criterio). Se registra una sola vez por estudiante y periodo, cuando el periodo
 * ya terminó (está cerrado) y el año lectivo sigue activo; después no se edita.
 */
class ConductaService
{
    public const VALORACIONES = ['Excelente', 'Bueno', 'Malo', 'Deficiente'];

    /** Criterios de conducta (textos de Josmy); cada uno se guarda en conductas.descripcion. */
    public const CRITERIOS = [
        'Se respeta a sí mismo y a los demás.',
        'Convive de forma armoniosa con sus compañeros.',
        'Toma decisiones responsables.',
        'Cumple con sus deberes y hace valer correctamente sus derechos.',
        'Practica valores morales y cívicos.',
    ];

    /** Conductas de un periodo en el orden de los criterios (una anterior sin criterio va al final). */
    public static function ordenar(Collection $conductas): Collection
    {
        return $conductas->sortBy(fn (Conducta $c) => [
            ($i = array_search($c->descripcion, self::CRITERIOS, true)) === false ? count(self::CRITERIOS) : $i, $c->id,
        ])->values();
    }

    /** Sección en la que el docente imparte una materia o es docente guía. */
    public function seccionPropia(User $docente, $seccionId): Seccion
    {
        $profesorId = $docente->profesor?->id ?? 0;

        return Seccion::with(['grado:id,nombre', 'anioLectivo'])
            ->where(fn ($q) => $q->where('profesor_id', $profesorId)
                ->orWhereHas('asignaciones', fn ($a) => $a->where('profesor_id', $profesorId)))
            ->findOrFail($seccionId);
    }

    /** Estudiantes de la sección con su evaluación de conducta del periodo (registrada o pendiente). */
    public function listar(Seccion $seccion, $periodoId = null): array
    {
        $periodos = PeriodoEscolar::deLaSeccion($seccion)->get(['id', 'nombre', 'nivel', 'estado', 'anio_lectivo_id']);
        $periodo = $periodoId ? $this->periodoDeLaSeccion($seccion, $periodoId) : $periodos->first()?->load('anioLectivo');

        $conductas = $periodo
            ? Conducta::where('periodo_escolar_id', $periodo->id)->whereIn('descripcion', self::CRITERIOS)->get()->groupBy('matricula_id')
            : collect();

        return [
            'seccion' => $seccion,
            'periodos' => $periodos,
            'periodo' => $periodo,
            'editable' => (bool) ($periodo && $this->permiteRegistrar($periodo)),
            'motivo' => $periodo && ! $this->permiteRegistrar($periodo) ? $this->motivoBloqueo($periodo) : null,
            'valoraciones' => self::VALORACIONES,
            'criterios' => self::CRITERIOS,
            'estudiantes' => $this->matriculas($seccion)->map(function (Matricula $m) use ($conductas) {
                $propias = ($conductas[$m->id] ?? collect())->keyBy('descripcion');

                return [
                    'matricula_id' => $m->id,
                    'estudiante' => $m->estudiante,
                    'registrada' => $propias->isNotEmpty(),
                    'criterios' => collect(self::CRITERIOS)->map(fn ($c) => [
                        'criterio' => $c, 'valoracion' => $propias->get($c)?->valoracion,
                    ])->values(),
                ];
            })->values(),
        ];
    }

    /** La conducta se registra cuando el periodo terminó (cerrado) y su año lectivo sigue activo. */
    public function permiteRegistrar(PeriodoEscolar $periodo): bool
    {
        return $periodo->estado === PeriodoEscolar::CERRADO
            && $periodo->anioLectivo?->estado === AnioLectivo::ACTIVO;
    }

    private function motivoBloqueo(PeriodoEscolar $periodo): string
    {
        return $periodo->estado !== PeriodoEscolar::CERRADO
            ? "La conducta se registra cuando el periodo {$periodo->nombre} termine (esté cerrado)."
            : 'El año lectivo de este periodo no está activo: la conducta solo se puede consultar.';
    }

    /**
     * Conducta.registrar(): los 5 criterios de un estudiante en el periodo, una sola
     * vez. Una conducta anterior (formato de una sola observación) se reemplaza.
     *
     * @param  array<int, string>  $valoraciones  una por criterio, en el orden de CRITERIOS
     */
    public function registrar(Seccion $seccion, $periodoId, $matriculaId, array $valoraciones): array
    {
        return DB::transaction(function () use ($seccion, $periodoId, $matriculaId, $valoraciones) {
            $periodo = $this->periodoDeLaSeccion($seccion, $periodoId);
            if (! $this->permiteRegistrar($periodo)) {
                throw new \DomainException($this->motivoBloqueo($periodo));
            }
            if (! $this->matriculas($seccion)->contains('id', (int) $matriculaId)) {
                throw ValidationException::withMessages(['matricula_id' => 'El estudiante no está matriculado en esta sección.']);
            }

            // Bloquea la matrícula para que dos docentes no la registren a la vez
            Matricula::whereKey($matriculaId)->lockForUpdate()->first();
            $anteriores = Conducta::where('periodo_escolar_id', $periodo->id)->where('matricula_id', $matriculaId);
            if ((clone $anteriores)->whereIn('descripcion', self::CRITERIOS)->exists()) {
                throw new \DomainException('La conducta de este estudiante ya está registrada en este periodo.');
            }
            $reemplazadas = (clone $anteriores)->delete();

            foreach (self::CRITERIOS as $i => $criterio) {
                Conducta::create([
                    'descripcion' => $criterio, 'valoracion' => $valoraciones[$i],
                    'matricula_id' => $matriculaId, 'periodo_escolar_id' => $periodo->id,
                ]);
            }

            return ['registradas' => count(self::CRITERIOS), 'reemplazadas' => $reemplazadas];
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
