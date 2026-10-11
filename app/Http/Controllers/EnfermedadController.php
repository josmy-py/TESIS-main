<?php

namespace App\Http\Controllers;

use App\Services\EnfermedadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de enfermedades (M2.1.4). Campos según la migración:
 * nombre varchar(50) único y medicamento varchar(50) obligatorio.
 */
class EnfermedadController extends Controller
{
    protected EnfermedadService $enfermedadService;

    private const MENSAJES = [
        'nombre.required' => 'El nombre de la enfermedad es obligatorio.',
        'nombre.string' => 'El nombre de la enfermedad debe ser una cadena de texto.',
        'nombre.max' => 'El nombre de la enfermedad no debe exceder los 50 caracteres.',
        'nombre.unique' => 'La enfermedad ya está registrada.',
        'medicamento.required' => 'El medicamento es obligatorio.',
        'medicamento.string' => 'El medicamento debe ser una cadena de texto.',
        'medicamento.max' => 'El medicamento no debe exceder los 50 caracteres.',
    ];

    public function __construct(EnfermedadService $enfermedadService)
    {
        $this->enfermedadService = $enfermedadService;
    }

    public function index()
    {
        try {
            return response()->json(['enfermedades' => $this->enfermedadService->listar()], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las enfermedades',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $datos = $request->validate([
                'nombre' => 'required|string|max:50|unique:enfermedades,nombre',
                'medicamento' => 'required|string|max:50',
            ], self::MENSAJES);

            $enfermedad = $this->enfermedadService->crear($datos);

            return response()->json(['message' => 'Enfermedad registrada exitosamente', 'enfermedad' => $enfermedad], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al registrar la enfermedad',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            return response()->json(['enfermedad' => $this->enfermedadService->obtener($id)], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: enfermedad no encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener la enfermedad',
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $this->enfermedadService->obtener($id);

            $datos = $request->validate([
                'nombre' => ['required', 'string', 'max:50', Rule::unique('enfermedades', 'nombre')->ignore($id)],
                'medicamento' => 'required|string|max:50',
            ], self::MENSAJES);

            $enfermedad = $this->enfermedadService->actualizar($id, $datos);

            return response()->json(['message' => 'Enfermedad actualizada exitosamente', 'enfermedad' => $enfermedad], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: enfermedad no encontrada',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la enfermedad',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $this->enfermedadService->eliminar($id);

            return response()->json(['message' => 'Enfermedad eliminada exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: enfermedad no encontrada',
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar la enfermedad',
            ], 500);
        }
    }
}
