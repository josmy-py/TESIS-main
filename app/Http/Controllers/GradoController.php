<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Grado;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class GradoController extends Controller
{
    // Orden académico (M2.1.1): entero positivo; la unicidad se agrega en cada acción
    private const REGLA_ORDEN = ['required', 'integer', 'min:1', 'max:999'];

    private const MENSAJES_ORDEN = [
        'orden.required' => 'El orden académico del grado es obligatorio.',
        'orden.integer' => 'El orden académico debe ser un número entero.',
        'orden.min' => 'El orden académico debe ser un número entero positivo.',
        'orden.max' => 'El orden académico no debe ser mayor que 999.',
        'orden.unique' => 'Ya existe un grado con ese orden académico.',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $grados = Grado::ordenAcademico()->get();
            return response()->json(['grados' => $grados], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los grados',
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
                'nombre' => 'required|string|max:50|unique:grados,nombre',
                'orden' => [...self::REGLA_ORDEN, 'unique:grados,orden'],
            ],
            [
                'nombre.required' => 'El nombre del grado es obligatorio.',
                'nombre.string' => 'El nombre del grado debe ser una cadena de texto.',
                'nombre.unique' => 'El nombre del grado ya está registrado.',
            ] + self::MENSAJES_ORDEN);

            $grado = Grado::create([
                'nombre' => $request->nombre,
                'orden' => (int) $request->orden,
            ]);

            return response()->json(['message' => 'Grado registrado exitosamente', 'grado' => $grado], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear el grado',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $grado = Grado::findOrFail($id);
            return response()->json(['grado' => $grado], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $grado = Grado::findOrFail($id);

            $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('grados', 'nombre')->ignore($id)
                ],
                // Obligatorio también al editar: así se completa el orden de los grados heredados
                'orden' => [...self::REGLA_ORDEN, Rule::unique('grados', 'orden')->ignore($id)],
            ],
            [
                'nombre.required' => 'El nombre del grado es obligatorio.',
                'nombre.string' => 'El nombre del grado debe ser una cadena de texto.',
                'nombre.max' => 'El nombre del grado no debe exceder los 50 caracteres.',
                'nombre.unique' => 'El nombre del grado ya existe.',
            ] + self::MENSAJES_ORDEN);

            $grado->update([
                'nombre' => $request->nombre,
                'orden' => (int) $request->orden,
            ]);

            return response()->json(['message' => 'Grado actualizado exitosamente', 'grado' => $grado], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el grado',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $grado = Grado::findOrFail($id);

            if ($grado->secciones()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar el grado porque tiene secciones o asignaciones asociadas'
                ], 409);
            }

            if ($grado->planesEstudio()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar el grado porque tiene planes de estudio asociados'
                ], 409);
            }

            $grado->delete();
            return response()->json(['message' => 'Grado eliminado exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: grado no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el grado',
            ], 500);
        }
    }
}
