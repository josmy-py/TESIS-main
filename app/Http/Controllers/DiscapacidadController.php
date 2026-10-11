<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Discapacidad;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class DiscapacidadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $discapacidad = Discapacidad::orderBy('id', 'desc')->get();
            return response()->json(['discapacidades' => $discapacidad], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las discapacidades',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:50|unique:discapacidades,nombre',
            ],
            [
                'nombre.required' => 'El nombre de la discapacidad es obligatorio.',
                'nombre.unique' => 'El nombre de la discapacidad ya está registrado.',
            ]);

            $discapacidad = Discapacidad::create([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Discapacidad registrado exitosamente', 'discapacidades' => $discapacidad], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la discapacidades',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $discapacidad = Discapacidad::findOrFail($id);
            return response()->json(['discapacidades' => $discapacidad], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: Discapacidad no encontrado',
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $discapacidad = Discapacidad::findOrFail($id);

            $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('discapacidades', 'nombre')->ignore($id)
                ],
            ],
            [
                'nombre.required' => 'El nombre de la discapacidad es obligatorio.',
                'nombre.unique' => 'El nombre de la discapacidad ya está registrado.',
            ]);

            $discapacidad->update([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Discapacidad actualizada exitosamente', 'discapacidades' => $discapacidad], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: Discapacidad no encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la discapacidad',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $discapacidad = Discapacidad::findOrFail($id);

            // Los expedientes de salud de las matrículas nunca se borran desde aquí
            if ($discapacidad->matriculas()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar la discapacidad porque está registrada en expedientes de estudiantes'
                ], 409);
            }

            $discapacidad->delete();
            return response()->json(['message' => 'Discapacidad eliminada exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: discapacidad no encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la discapacidad',
            ], 500);
        }
    }
}
