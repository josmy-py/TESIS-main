<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Especialidad;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class EspecialidadController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $espe = Especialidad::orderBy('id', 'desc')->get();
            return response()->json(['especialidades' => $espe], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las especialidades',
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
                'nombre' => 'required|string|max:20|unique:especialidades,nombre',
            ],
            [
                'nombre.required' => 'El nombre del la especialidad es obligatorio.',
                'nombre.max' => 'El nombre de la especialidad no debe exceder los 20 caracteres.',
                'nombre.unique' => 'El nombre de la especialidad ya está registrado.',
            ]);

            $espe = Especialidad::create([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Especialidad registrado exitosamente', 'especialidad' => $espe], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la especialidad',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $espe = Especialidad::findOrFail($id);
            return response()->json(['especialidad' => $espe], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: Especialidad no encontrada',
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $espe = Especialidad::findOrFail($id);

            $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('especialidades', 'nombre')->ignore($id)
                ],
            ],
            [
                'nombre.required' => 'El nombre de la especialidad es obligatoria.',
                'nombre.max' => 'El nombre de la especialidad no debe exceder los 20 caracteres.',
                'nombre.unique' => 'El nombre de la especialidad ya existe.',
            ]);

            $espe->update([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Especialidad actualizado exitosamente', 'especialidad' => $espe], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: Especialidad no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la especialidad',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $espe = Especialidad::findOrFail($id);

            if ($espe->matricula()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar la especialidad porque tiene matriculas asociadas'
                ], 409);
            }

            // M2.1.2: plan_estudios.especialidad_id también referencia la especialidad
            if ($espe->planesEstudios()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar la especialidad porque tiene planes de estudio asociados'
                ], 409);
            }

            $espe->delete();
            return response()->json(['message' => 'Especialidad eliminado exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: Especialidad no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la especialidad',
            ], 500);
        }
    }
}
