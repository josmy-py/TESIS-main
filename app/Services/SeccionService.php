<?php

namespace App\Services;

use App\Models\Seccion;

class SeccionService
{
    public function listar()
    {
        return Seccion::with('grado', 'profesor')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtener($id)
    {
        return Seccion::with('grado', 'profesor')->findOrFail($id);
    }

    public function crear(array $datos)
    {
        $existSeccion = Seccion::where('nombre', $datos['nombre'])
            ->where('grado_id', $datos['grado_id'])
            ->exists();

        if ($existSeccion) {
            return null;
        }

        return Seccion::create([
            'nombre' => $datos['nombre'],
            'capacidad' => $datos['capacidad'],
            'turno' => $datos['turno'],
            'anio' => $datos['anio'],
            'grado_id' => $datos['grado_id'],
            'profesor_id' => $datos['profesor_id'],
        ]);
    }

    public function actualizar($id, array $datos)
    {
        $seccion = Seccion::findOrFail($id);

        $existSeccion = Seccion::where('nombre', $datos['nombre'])
            ->where('grado_id', $datos['grado_id'])
            ->where('id', '<>', $id)
            ->exists();

        if ($existSeccion) {
            return null;
        }

        $seccion->update([
            'nombre' => $datos['nombre'],
            'capacidad' => $datos['capacidad'],
            'turno' => $datos['turno'],
            'anio' => $datos['anio'],
            'grado_id' => $datos['grado_id'],
            'profesor_id' => $datos['profesor_id'],
        ]);

        return $seccion;
    }

    public function eliminar($id)
    {
        $seccion = Seccion::findOrFail($id);

        if ($seccion->matriculas()->exists()) {
            return 'matriculas';
        }

        if ($seccion->asignaciones()->exists()) {
            return 'asignaciones';
        }

        $seccion->delete();

        return true;
    }
}
