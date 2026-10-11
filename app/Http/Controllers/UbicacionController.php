<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Consultas de la división territorial (solo lectura). Los formularios eligen
 * en cascada: departamento -> municipio -> distrito; estudiantes guarda distrito_id.
 */
class UbicacionController extends Controller
{
    public function departamentos()
    {
        $departamentos = Departamento::orderBy('nombre')->get(['id', 'nombre']);

        return response()->json(['departamentos' => $departamentos], 200);
    }

    public function municipios(string $departamentoId)
    {
        try {
            $departamento = Departamento::findOrFail($departamentoId);
            $municipios = $departamento->municipios()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']);

            return response()->json(['departamento' => $departamento->only('id', 'nombre'), 'municipios' => $municipios], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Departamento no encontrado',
            ], 404);
        }
    }

    public function distritosDeMunicipio(string $municipioId)
    {
        try {
            $municipio = Municipio::with('departamento:id,nombre')->findOrFail($municipioId);
            $distritos = $municipio->distritos()->orderBy('nombre')->get(['id', 'nombre', 'municipio_id']);

            return response()->json(['municipio' => $municipio->only('id', 'nombre', 'departamento_id', 'departamento'), 'distritos' => $distritos], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Municipio no encontrado',
            ], 404);
        }
    }

    public function distritos()
    {
        $distritos = Distrito::with('municipio.departamento')
            ->orderBy('nombre')
            ->get();

        return response()->json(['distritos' => $distritos], 200);
    }

    public function distrito(string $id)
    {
        try {
            $distrito = Distrito::with('municipio.departamento')->findOrFail($id);

            return response()->json(['distrito' => $distrito], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Distrito no encontrado',
            ], 404);
        }
    }
}
