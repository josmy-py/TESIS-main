<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Asignatura;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AsignaturaController extends Controller
{
    private const MENSAJES = [
        'plan_estudio_id.required' => 'El plan de estudio es obligatorio.',
        'plan_estudio_id.exists' => 'El plan de estudio seleccionado no es válido.',
        'nombre.required' => 'El nombre de la asignatura es obligatorio.',
        'nombre.max' => 'El nombre de la asignatura no debe exceder los 50 caracteres.',
        'nombre.unique' => 'La asignatura ya existe en ese plan de estudio.',
    ];

    /**
     * Display a listing of the resource.
     * Filtro opcional: plan_estudio_id.
     */
    public function index(Request $request)
    {
        try {
            $asignatura = Asignatura::with('planEstudio:id,nombre,especialidad_id,estado')
                ->when($request->query('plan_estudio_id'), fn ($q, $planId) => $q->where('plan_estudio_id', $planId))
                ->orderBy('nombre')
                ->get();
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
                'plan_estudio_id' => 'required|integer|exists:plan_estudios,id',
                // M2.3: el nombre es único dentro de cada plan (cada grado tiene su "Matemática")
                'nombre' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('asignaturas', 'nombre')->where('plan_estudio_id', $request->plan_estudio_id),
                ],
            ], self::MENSAJES);

            $asignatura = Asignatura::create([
                'nombre' => $request->nombre,
                'plan_estudio_id' => $request->plan_estudio_id,
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
                'plan_estudio_id' => 'required|integer|exists:plan_estudios,id',
                'nombre' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('asignaturas', 'nombre')->where('plan_estudio_id', $request->plan_estudio_id)->ignore($id),
                ],
            ], self::MENSAJES);

            // Con carga académica, moverla de plan cambiaría el pensum de esas secciones
            if ((int) $asignatura->plan_estudio_id !== (int) $request->plan_estudio_id && $asignatura->asignaciones()->exists()) {
                return response()->json(['message' => 'No se puede cambiar el plan de la asignatura porque tiene asignaciones asociadas'], 409);
            }

            $asignatura->update([
                'nombre' => $request->nombre,
                'plan_estudio_id' => $request->plan_estudio_id,
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
                return response()->json(['message' => 'No se puede eliminar la asignatura porque tiene asignaciones asociadas'], 409);
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
