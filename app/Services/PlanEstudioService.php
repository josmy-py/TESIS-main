<?php

namespace App\Services;

use App\Models\PlanEstudio;

/**
 * Planes de estudio (M2.3, D11). Cada plan pertenece a un grado y sus
 * asignaturas forman el pensum de ese grado. Solo un plan vigente (estado =
 * true) por grado y especialidad.
 */
class PlanEstudioService
{
    public function listar(array $filtros = [])
    {
        $consulta = PlanEstudio::with(['grado:id,nombre', 'especialidad:id,nombre'])
            ->withCount('asignaturas')
            ->orderByDesc('estado')
            ->orderBy('nombre');

        if (! empty($filtros['grado_id'])) {
            $consulta->where('grado_id', $filtros['grado_id']);
        }
        if (! empty($filtros['sin_grado'])) {
            $consulta->whereNull('grado_id');
        }
        if (array_key_exists('vigente', $filtros) && $filtros['vigente'] !== null) {
            $consulta->where('estado', (bool) $filtros['vigente']);
        }

        return $consulta->get();
    }

    public function obtener($id)
    {
        return PlanEstudio::with(['grado:id,nombre', 'especialidad:id,nombre', 'asignaturas' => fn ($q) => $q->orderBy('nombre')])
            ->findOrFail($id);
    }

    public function crear(array $datos)
    {
        $this->validarUnicoVigente($datos);

        return PlanEstudio::create($this->campos($datos));
    }

    public function actualizar($id, array $datos)
    {
        $plan = PlanEstudio::findOrFail($id);

        // Cambiar de grado un plan con carga académica rompería el pensum de esas secciones
        if ((int) $plan->grado_id !== (int) $datos['grado_id'] && $this->tieneAsignaciones($plan)) {
            throw new \DomainException('No se puede cambiar el grado del plan porque sus asignaturas ya tienen asignaciones.');
        }

        $this->validarUnicoVigente($datos, $plan->id);
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
            'grado_id' => $datos['grado_id'],
            'especialidad_id' => $datos['especialidad_id'] ?? null,
            'estado' => (bool) $datos['estado'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin' => $datos['fecha_fin'],
            'descripcion' => $datos['descripcion'],
            'fecha_aprobacion' => $datos['fecha_aprobacion'],
        ];
    }

    private function validarUnicoVigente(array $datos, $ignorarId = null): void
    {
        if (! (bool) $datos['estado']) {
            return;
        }

        $existe = PlanEstudio::where('estado', true)
            ->where('grado_id', $datos['grado_id'])
            ->when(
                empty($datos['especialidad_id']),
                fn ($q) => $q->whereNull('especialidad_id'),
                fn ($q) => $q->where('especialidad_id', $datos['especialidad_id'])
            )
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw new \DomainException('Ya existe un plan de estudio vigente para ese grado y especialidad. Marque el anterior como no vigente antes de activar otro.');
        }
    }

    private function tieneAsignaciones(PlanEstudio $plan): bool
    {
        return $plan->asignaturas()->whereHas('asignaciones')->exists();
    }
}
