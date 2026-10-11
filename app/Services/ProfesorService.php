<?php

namespace App\Services;

use App\Models\Profesor;

class ProfesorService
{
    public function listar()
    {
        return Profesor::with('user')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtener($id)
    {
        return Profesor::with('user')->findOrFail($id);
    }

    public function crear(array $data)
    {
        return Profesor::create([
            'nombre' => $data['nombre'],
            'dui' => $data['dui'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'codigo' => $data['codigo'],
            'telefono' => $data['telefono'],
            'direccion' => $data['direccion'],
            'user_id' => $data['user_id'],
        ]);
    }

    public function actualizar($id, array $data)
    {
        $profesor = Profesor::findOrFail($id);

        $profesor->update([
            'nombre' => $data['nombre'],
            'dui' => $data['dui'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'codigo' => $data['codigo'],
            'telefono' => $data['telefono'],
            'direccion' => $data['direccion'],
            'user_id' => $data['user_id'],
        ]);

        return $profesor->load('user');
    }

    public function eliminar($id)
    {
        $profesor = Profesor::with('secciones', 'asignaciones')->findOrFail($id);

        if ($profesor->secciones()->exists() || $profesor->asignaciones()->exists()) {
            return false;
        }

        $profesor->delete();

        return true;
    }
}
