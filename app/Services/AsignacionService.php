<?php

namespace App\Services;

use App\Models\Asignacion;

class AsignacionService
{
    public function listar()
    {
        return Asignacion::with('profesor', 'asignatura', 'seccion')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtener($id)
    {
        return Asignacion::with('profesor', 'asignatura', 'seccion')
            ->findOrFail($id);
    }

    public function crear(array $datos)
    {
        // El año de la asignación es el de su sección (secciones.anio_lectivo_id)
        $existe = Asignacion::where('profesor_id', $datos['profesor_id'])
            ->where('asignatura_id', $datos['asignatura_id'])
            ->where('seccion_id', $datos['seccion_id'])
            ->exists();

        if ($existe) {
            return null;
        }

        return Asignacion::create([
            'profesor_id' => $datos['profesor_id'],
            'asignatura_id' => $datos['asignatura_id'],
            'seccion_id' => $datos['seccion_id'],
        ]);
    }

    public function eliminar($id)
    {
        $asignacion = Asignacion::findOrFail($id);

        $asignacion->delete();

        return true;
    }
}
