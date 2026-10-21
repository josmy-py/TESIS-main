<?php

namespace App\Services;

use App\Models\Asignatura;
use App\Models\PlanAsignatura;
use App\Models\PlanEstudio;
use App\Support\NombreUnico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asignaturas de cada plan de estudios (diagrama ER 21/10/2026, tabla
 * plan_asignaturas). El director escribe el nombre de la materia dentro del
 * plan: si ya existe en el catálogo de asignaturas (sin contar mayúsculas,
 * tildes ni espacios) se reutiliza; si no, se crea. Así una asignatura puede
 * estar en varios planes sin repetirse en el catálogo.
 * DomainException responde 409 y ValidationException 422.
 */
class PlanAsignaturaService
{
    /** Filtro opcional: plan_estudio_id. Cada fila trae el nombre de su asignatura. */
    public function listar(array $filtros = [])
    {
        return PlanAsignatura::with(['asignatura', 'planEstudio:id,nombre,especialidad_id,estado'])
            ->when($filtros['plan_estudio_id'] ?? null, fn ($q, $planId) => $q->where('plan_estudio_id', $planId))
            ->get()
            ->map(fn (PlanAsignatura $pa) => $this->formato($pa))
            ->sortBy(fn ($pa) => mb_strtolower((string) $pa['nombre']))
            ->values();
    }

    public function obtener($id): array
    {
        return $this->formato(PlanAsignatura::with(['asignatura', 'planEstudio:id,nombre,especialidad_id,estado'])->findOrFail($id));
    }

    /** Datos: plan_estudio_id y nombre de la materia. */
    public function agregar(array $datos): array
    {
        return DB::transaction(function () use ($datos) {
            $plan = PlanEstudio::findOrFail($datos['plan_estudio_id']);
            $asignatura = $this->asignaturaDelCatalogo($datos['nombre']);
            $this->asegurarLibreEnPlan($plan, $asignatura);

            $pa = PlanAsignatura::create(['plan_estudio_id' => $plan->id, 'asignatura_id' => $asignatura->id]);

            return $this->obtener($pa->id);
        });
    }

    /**
     * Cambia el nombre de la materia en este plan. Si la asignatura solo está
     * en este plan se renombra; si la comparten otros planes, este plan pasa a
     * usar la asignatura con el nombre nuevo y los demás no cambian.
     */
    public function renombrar($id, string $nombre): array
    {
        return DB::transaction(function () use ($id, $nombre) {
            $pa = PlanAsignatura::with('asignatura', 'planEstudio')->lockForUpdate()->findOrFail($id);
            $nombre = trim($nombre);
            $otra = $this->buscar($nombre);

            if ($otra && $otra->id !== $pa->asignatura_id) {
                $this->asegurarLibreEnPlan($pa->planEstudio, $otra, $pa->id);
                $anterior = $pa->asignatura;
                $pa->update(['asignatura_id' => $otra->id]);
                $this->quitarSiNoSeUsa($anterior);
            } elseif ($pa->asignatura->planAsignaturas()->whereKeyNot($pa->id)->exists() && ! $otra) {
                $pa->update(['asignatura_id' => Asignatura::create(['nombre' => $nombre])->id]);
            } else {
                $pa->asignatura->update(['nombre' => $nombre]);
            }

            return $this->obtener($pa->id);
        });
    }

    public function quitar($id): void
    {
        DB::transaction(function () use ($id) {
            $pa = PlanAsignatura::with('asignatura')->lockForUpdate()->findOrFail($id);
            if ($pa->asignaciones()->exists()) {
                throw new \DomainException("No se puede quitar {$pa->asignatura?->nombre} del plan porque tiene docentes asignados.");
            }
            $asignatura = $pa->asignatura;
            $pa->delete();
            $this->quitarSiNoSeUsa($asignatura);
        });
    }

    public function formato(PlanAsignatura $pa): array
    {
        return [
            'id' => $pa->id,
            'plan_estudio_id' => $pa->plan_estudio_id,
            'asignatura_id' => $pa->asignatura_id,
            'nombre' => $pa->asignatura?->nombre,
            'plan_estudio' => $pa->planEstudio,
        ];
    }

    private function buscar(string $nombre): ?Asignatura
    {
        $clave = NombreUnico::clave($nombre);

        return Asignatura::all(['id', 'nombre'])->first(fn ($a) => NombreUnico::clave($a->nombre) === $clave);
    }

    private function asignaturaDelCatalogo(string $nombre): Asignatura
    {
        return $this->buscar($nombre) ?? Asignatura::create(['nombre' => trim($nombre)]);
    }

    private function asegurarLibreEnPlan(PlanEstudio $plan, Asignatura $asignatura, ?int $excepto = null): void
    {
        $ocupada = PlanAsignatura::where('plan_estudio_id', $plan->id)->where('asignatura_id', $asignatura->id)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->exists();
        if ($ocupada) {
            throw ValidationException::withMessages(['nombre' => 'La asignatura ya existe en ese plan de estudio.']);
        }
    }

    // El catálogo no guarda asignaturas que ya ningún plan usa
    private function quitarSiNoSeUsa(?Asignatura $asignatura): void
    {
        if ($asignatura && ! $asignatura->planAsignaturas()->exists()) {
            $asignatura->delete();
        }
    }
}
