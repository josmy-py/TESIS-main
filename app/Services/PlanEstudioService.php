<?php

namespace App\Services;

use App\Models\PlanEstudio;

/**
 * Planes de estudio (M2.3), con los campos del diagrama: nombre, especialidad
 * (opcional), estado (vigente o no), fechas y descripción.
 */
class PlanEstudioService
{
    public function listar(array $filtros = [])
    {
        $consulta = PlanEstudio::with(['especialidad:id,nombre'])
            ->withCount('asignaturas')
            ->orderByDesc('estado')
            ->orderBy('nombre');

        if (array_key_exists('vigente', $filtros) && $filtros['vigente'] !== null) {
            $consulta->where('estado', (bool) $filtros['vigente']);
        }

        return $consulta->get();
    }

    public function obtener($id)
    {
        return PlanEstudio::with(['especialidad:id,nombre', 'asignaturas' => fn ($q) => $q->orderBy('nombre')])
            ->findOrFail($id);
    }

    public function crear(array $datos)
    {
        return PlanEstudio::create($this->campos($datos));
    }

    public function actualizar($id, array $datos)
    {
        $plan = PlanEstudio::findOrFail($id);
        $plan->update($this->campos($datos));

        return $plan;
    }

    public function eliminar($id): void
    {
        $plan = PlanEstudio::findOrFail($id);

        if ($plan->asignaturas()->exists()) {
            throw new \DomainException('No se puede eliminar el plan de estudio porque tiene asignaturas registradas.');
        }

        $plan->delete();
    }

    private function campos(array $datos): array
    {
        return [
            'nombre' => $datos['nombre'],
            'especialidad_id' => $datos['especialidad_id'] ?? null,
            'estado' => (bool) $datos['estado'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin' => $datos['fecha_fin'],
            'descripcion' => $datos['descripcion'],
            'fecha_aprobacion' => $datos['fecha_aprobacion'],
        ];
    }
}
