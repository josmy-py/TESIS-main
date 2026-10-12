<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AsignacionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AsignacionController extends Controller
{
    protected $asignacionService;

    public function __construct(AsignacionService $asignacionService)
    {
        $this->asignacionService = $asignacionService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $asignaciones = $this->asignacionService->listar();

            return response()->json([
                'asignaciones' => $asignaciones
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las asignaciones',
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
                'profesor_id' => 'required|exists:profesores,id',
                'asignatura_id' => 'required|exists:asignaturas,id',
                'seccion_id' => 'required|exists:secciones,id',
            ], [
                'profesor_id.required' => 'El profesor es obligatorio.',
                'asignatura_id.required' => 'La asignatura es obligatoria.',
                'seccion_id.required' => 'La sección es obligatoria.',
            ]);

            $asignacion = $this->asignacionService->crear($request->all());

            if (!$asignacion) {
                return response()->json([
                    'message' => 'La asignación ya existe para el profesor, asignatura y sección especificados.'
                ], 422);
            }

            return response()->json([
                'message' => 'Asignación registrada exitosamente',
                'asignacion' => $asignacion
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la asignación',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $asignacion = $this->asignacionService->obtener($id);

            return response()->json([
                'asignacion' => $asignacion
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: asignación no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener la asignación',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $this->asignacionService->eliminar($id);

            return response()->json([
                'message' => 'Asignación eliminada exitosamente'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: asignación no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la asignación',
            ], 500);
        }
    }
}
