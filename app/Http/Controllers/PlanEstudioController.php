<?php

namespace App\Http\Controllers;

use App\Services\PlanEstudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Planes de estudio (M2.3). Campos del diagrama: nombre varchar(30),
 * especialidad_id opcional (solo Bachillerato), estado
 * (vigente o no), fechas de inicio, fin y aprobación, y descripción.
 */
class PlanEstudioController extends Controller
{
    protected PlanEstudioService $planEstudioService;

    private const MENSAJES = [
        'nombre.required' => 'El nombre del plan de estudio es obligatorio.',
        'nombre.max' => 'El nombre del plan de estudio no debe exceder los 30 caracteres.',
        'especialidad_id.exists' => 'La especialidad seleccionada no es válida.',
        'estado.required' => 'Indique si el plan está vigente.',
        'estado.boolean' => 'El estado debe ser verdadero o falso.',
        'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
        'fecha_inicio.date' => 'La fecha de inicio no es válida.',
        'fecha_fin.required' => 'La fecha de fin es obligatoria.',
        'fecha_fin.date' => 'La fecha de fin no es válida.',
        'fecha_fin.after' => 'La fecha de fin debe ser posterior a la fecha de inicio.',
        'fecha_aprobacion.required' => 'La fecha de aprobación es obligatoria.',
        'fecha_aprobacion.date' => 'La fecha de aprobación no es válida.',
        'descripcion.required' => 'La descripción es obligatoria.',
        'descripcion.max' => 'La descripción no debe exceder los 200 caracteres.',
    ];

    public function __construct(PlanEstudioService $planEstudioService)
    {
        $this->planEstudioService = $planEstudioService;
    }

    public function index(Request $request)
    {
        try {
            $filtros = [
                'vigente' => $request->has('vigente') ? $request->boolean('vigente') : null,
            ];

            return response()->json(['planes' => $this->planEstudioService->listar($filtros)], 200);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los planes de estudio',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $plan = $this->planEstudioService->crear($this->validar($request));

            return response()->json(['message' => 'Plan de estudio registrado exitosamente', 'plan' => $plan], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al registrar el plan de estudio',
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            return response()->json(['plan' => $this->planEstudioService->obtener($id)], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: plan de estudio no encontrado',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener el plan de estudio',
            ], 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $this->planEstudioService->obtener($id);
            $plan = $this->planEstudioService->actualizar($id, $this->validar($request));

            return response()->json(['message' => 'Plan de estudio actualizado exitosamente', 'plan' => $plan], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: plan de estudio no encontrado',
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el plan de estudio',
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $this->planEstudioService->eliminar($id);

            return response()->json(['message' => 'Plan de estudio eliminado exitosamente'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Error: plan de estudio no encontrado',
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al eliminar el plan de estudio',
            ], 500);
        }
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => 'required|string|max:30',
            'especialidad_id' => 'nullable|integer|exists:especialidades,id',
            'estado' => 'required|boolean',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after:fecha_inicio',
            'fecha_aprobacion' => 'required|date',
            'descripcion' => 'required|string|max:200',
        ], self::MENSAJES);
    }
}
