<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Seccion;
use Illuminate\Validation\ValidationException;

class SeccionService
{
    public function listar()
    {
        return Seccion::with('grado', 'profesor', 'anioLectivo')
            ->orderBy('id', 'desc')
            ->get();
    }

    public function obtener($id)
    {
        return Seccion::with('grado', 'profesor', 'anioLectivo')->findOrFail($id);
    }

    public function crear(array $datos)
    {
        $anioLectivo = $this->anioLectivo($datos);

        // M2.2: el mismo nombre de sección puede repetirse en otro año lectivo
        $existSeccion = Seccion::where('nombre', $datos['nombre'])
            ->where('grado_id', $datos['grado_id'])
            ->where('anio_lectivo_id', $anioLectivo->id)
            ->exists();

        if ($existSeccion) {
            return null;
        }

        return Seccion::create([
            'nombre' => $datos['nombre'],
            'capacidad' => $datos['capacidad'],
            'turno' => $datos['turno'],
            'anio_lectivo_id' => $anioLectivo->id,
            'grado_id' => $datos['grado_id'],
            'profesor_id' => $datos['profesor_id'],
        ]);
    }

    public function actualizar($id, array $datos)
    {
        $seccion = Seccion::with('anioLectivo')->findOrFail($id);
        $anioLectivo = $this->anioLectivo($datos);

        if ($seccion->anioLectivo?->estado === AnioLectivo::CIERRE) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'La sección pertenece a un año lectivo cerrado y no puede modificarse.']);
        }
        if ($seccion->anio_lectivo_id !== null && $seccion->anio_lectivo_id !== $anioLectivo->id && $seccion->matriculas()->exists()) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'La sección tiene matrículas y no puede cambiar de año lectivo.']);
        }

        $existSeccion = Seccion::where('nombre', $datos['nombre'])
            ->where('grado_id', $datos['grado_id'])
            ->where('anio_lectivo_id', $anioLectivo->id)
            ->where('id', '<>', $id)
            ->exists();

        if ($existSeccion) {
            return null;
        }

        $seccion->update([
            'nombre' => $datos['nombre'],
            'capacidad' => $datos['capacidad'],
            'turno' => $datos['turno'],
            'anio_lectivo_id' => $anioLectivo->id,
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

    private function anioLectivo(array $datos): AnioLectivo
    {
        return app(CalendarioService::class)->anioParaSeccion($datos['anio_lectivo_id']);
    }
}
