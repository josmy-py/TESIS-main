<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Asignatura;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AsignaturaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $asignatura = Asignatura::orderBy('id', 'desc')->get();
            return response()->json(['asignaturas' => $asignatura], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las asignaturas',
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
                'nombre' => 'required|string|max:50|unique:asignaturas,nombre',
            ],
            [
                'nombre.required' => 'El nombre de la asigantura es obligatorio.',
                'nombre.unique' => 'El nombre de la asignatura ya existe.',
            ]);

            $asignatura = Asignatura::create([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Asignatura registrada exitosamente', 'asignatura' => $asignatura], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la asignatura',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $asignatura = Asignatura::findOrFail($id);
            return response()->json($asignatura);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error la asignatura no fue encontrada',
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $asignatura = Asignatura::findOrFail($id);

            $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('asignaturas', 'nombre')->ignore($id)
                ],
            ],
            [
                'nombre.unique' => 'El nombre de la asignatura ya existe.',
            ]);

            $asignatura->update([
                'nombre' => $request->nombre,
            ]);

            return response()->json(['message' => 'Asignatura actualizada exitosamente', 'asignatura' => $asignatura], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error la asignatura no fue encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la asignatura',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $asignatura = Asignatura::with('asignaciones')->findOrFail($id);
            if ($asignatura->asignaciones()->exists()) {
                return response()->json(['message' => 'No se puede eliminar la asignatura porque tiene asignaciones asociadas'], 400);
            }

            $asignatura->delete();
            return response()->json(['message' => 'Asignatura eliminada exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error la asignatura no fue encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la asignatura',
            ], 500);
        }
    }
}
