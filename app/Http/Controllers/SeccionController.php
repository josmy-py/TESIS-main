<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SeccionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class SeccionController extends Controller
{
    protected SeccionService $seccionService;

    public function __construct(SeccionService $seccionService)
    {
        $this->seccionService = $seccionService;
    }

    public function index()
    {
        try {
            $secciones = $this->seccionService->listar();

            return response()->json([
                'secciones' => $secciones
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las secciones',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $datos = $request->validate([
                'nombre' => 'required|string|max:10',
                'capacidad' => 'required|integer',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'required|integer',
                'grado_id' => 'required|exists:grados,id',
                'profesor_id' => 'required|exists:profesores,id',
            ], [
                'nombre.required' => 'El nombre de la sección es obligatorio.',
                'capacidad.required' => 'La capacidad de la sección es obligatoria.',
                'turno.required' => 'El turno de la sección es obligatorio.',
                'nombre.max' => 'El nombre de la sección no debe exceder los 10 caracteres.',
                'anio_lectivo_id.required' => 'El año lectivo de la sección es obligatorio.',
                'grado_id.exists' => 'El grado seleccionado no es válido.',
                'profesor_id.exists' => 'El profesor seleccionado no es válido.',
            ]);

            $seccion = $this->seccionService->crear($datos);

            if (!$seccion) {
                return response()->json([
                    'message' => 'Error: La sección ya está registrada para este grado.'
                ], 400);
            }

            return response()->json([
                'message' => 'Sección registrada exitosamente',
                'seccion' => $seccion
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la sección',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $seccion = $this->seccionService->obtener($id);

            return response()->json($seccion, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: sección no encontrada',
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $datos = $request->validate([
                'nombre' => 'required|string|max:10',
                'capacidad' => 'required|integer',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'required|integer',
                'grado_id' => 'required|exists:grados,id',
                'profesor_id' => 'required|exists:profesores,id',
            ], [
                'nombre.required' => 'El nombre de la sección es obligatorio.',
                'capacidad.required' => 'La capacidad de la sección es obligatoria.',
                'turno.required' => 'El turno de la sección es obligatorio.',
                'nombre.max' => 'El nombre de la sección no debe exceder los 10 caracteres.',
                'anio_lectivo_id.required' => 'El año lectivo de la sección es obligatorio.',
                'grado_id.exists' => 'El grado seleccionado no es válido.',
                'profesor_id.exists' => 'El profesor seleccionado no es válido.',
            ]);

            $seccion = $this->seccionService->actualizar($id, $datos);

            if (!$seccion) {
                return response()->json([
                    'message' => 'Error: La sección ya está registrada para este grado.'
                ], 400);
            }

            return response()->json([
                'message' => 'Sección actualizada exitosamente',
                'seccion' => $seccion
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: sección no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la sección',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $resultado = $this->seccionService->eliminar($id);

            if ($resultado === 'matriculas') {
                return response()->json([
                    'message' => 'Error: no se puede eliminar la sección porque tiene alumnos asociados.'
                ], 400);
            }

            if ($resultado === 'asignaciones') {
                return response()->json([
                    'message' => 'Error: no se puede eliminar la sección porque tiene asignaciones asociadas.'
                ], 400);
            }

            return response()->json([
                'message' => 'Sección eliminada exitosamente'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: sección no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la sección',
            ], 500);
        }
    }
}
