<?php

namespace App\Http\Controllers;

use App\Services\PlanAsignaturaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Materias de cada plan de estudios (tabla plan_asignaturas del diagrama ER).
 * Consulta: cualquier cuenta con sesión. Agregar, renombrar y quitar: DIRECTOR.
 */
class PlanAsignaturaController extends Controller
{
    private const MENSAJES = [
        'plan_estudio_id.required' => 'El plan de estudio es obligatorio.',
        'plan_estudio_id.exists' => 'El plan de estudio seleccionado no es válido.',
        'nombre.required' => 'El nombre de la asignatura es obligatorio.',
        'nombre.max' => 'El nombre de la asignatura no debe exceder los 50 caracteres.',
    ];

    public function __construct(private PlanAsignaturaService $servicio)
    {
    }

    /** Filtro opcional: plan_estudio_id. */
    public function index(Request $request)
    {
        return $this->responder(fn () => response()->json([
            'plan_asignaturas' => $this->servicio->listar($request->only('plan_estudio_id')),
        ]), 'Error al obtener las asignaturas del plan');
    }

    public function show(string $id)
    {
        return $this->responder(fn () => response()->json(['plan_asignatura' => $this->servicio->obtener($id)]), 'Error al obtener la asignatura del plan');
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'plan_estudio_id' => 'required|integer|exists:plan_estudios,id',
                'nombre' => 'required|string|max:50',
            ], self::MENSAJES);

            return response()->json([
                'message' => 'Asignatura agregada al plan',
                'plan_asignatura' => $this->servicio->agregar($datos),
            ], 201);
        }, 'Error al agregar la asignatura al plan');
    }

    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->servicio->obtener($id);
            $datos = $request->validate(['nombre' => 'required|string|max:50'], self::MENSAJES);

            return response()->json([
                'message' => 'Asignatura actualizada exitosamente',
                'plan_asignatura' => $this->servicio->renombrar($id, $datos['nombre']),
            ]);
        }, 'Error al actualizar la asignatura del plan');
    }

    public function destroy(string $id)
    {
        return $this->responder(function () use ($id) {
            $this->servicio->quitar($id);

            return response()->json(['message' => 'Asignatura quitada del plan']);
        }, 'Error al quitar la asignatura del plan');
    }

    private function responder(callable $accion, string $error)
    {
        try {
            return $accion();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'La asignatura del plan no fue encontrada'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => $error], 500);
        }
    }
}
