<?php

namespace App\Services;

use App\Models\Enfermedad;

class EnfermedadService
{
    public function listar()
    {
        return Enfermedad::orderBy('nombre')->get();
    }

    public function obtener($id)
    {
        return Enfermedad::findOrFail($id);
    }

    public function crear(array $datos)
    {
        return Enfermedad::create([
            'nombre' => $datos['nombre'],
            'medicamento' => $datos['medicamento'],
        ]);
    }

    public function actualizar($id, array $datos)
    {
        $enfermedad = Enfermedad::findOrFail($id);

        $enfermedad->update([
            'nombre' => $datos['nombre'],
            'medicamento' => $datos['medicamento'],
        ]);

        return $enfermedad;
    }

    public function eliminar($id): void
    {
        $enfermedad = Enfermedad::findOrFail($id);

        // matriculas.enfermedad_id es obligatorio: las matrículas nunca se borran ni se alteran desde aquí
        if ($enfermedad->matriculas()->exists()) {
            throw new \DomainException('No se puede eliminar la enfermedad porque está registrada en matrículas.');
        }

        $enfermedad->delete();
    }
}
