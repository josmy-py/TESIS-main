<?php

namespace App\Http\Controllers;

use App\Services\AsignacionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * M4: asignación académica. El DIRECTOR administra la carga de los docentes;
 * cada DOCENTE consulta solo la suya (GET docente/carga). 409 para conflictos
 * y 422 para datos inválidos.
 */
class AsignacionController extends Controller
{
    protected AsignacionService $asignacionService;

    public function __construct(AsignacionService $asignacionService)
    {
        $this->asignacionService = $asignacionService;
    }

    public function index(Request $request)
    {
        return $this->responder(function () use ($request) {
            $filtros = $request->validate([
                'anio_lectivo_id' => 'nullable|integer|exists:anios_lectivos,id',
                'profesor_id' => 'nullable|integer',
                'seccion_id' => 'nullable|integer',
                'grado_id' => 'nullable|integer',
            ], $this->mensajes());

            return response()->json($this->asignacionService->listar($filtros), 200);
        }, 'Error al obtener las asignaciones');
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'profesor_id' => 'required|integer|exists:profesores,id',
                'plan_asignatura_id' => 'required|integer|exists:plan_asignaturas,id',
                'seccion_id' => 'required|integer|exists:secciones,id',
            ], $this->mensajes());

            $asignacion = $this->asignacionService->crear($datos, $request->user());

            return response()->json(['message' => 'Asignación registrada exitosamente', 'asignacion' => $asignacion], 201);
        }, 'Error al crear la asignación');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json(['asignacion' => $this->asignacionService->obtener($id)], 200),
            'Error al obtener la asignación'
        );
    }

    /** D-A3: solo cambia el docente; la materia y la sección no se mueven. */
    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->asignacionService->obtener($id);
            $datos = $request->validate([
                'profesor_id' => 'required|integer|exists:profesores,id',
            ], $this->mensajes());

            $asignacion = $this->asignacionService->cambiarDocente($id, $datos['profesor_id'], $request->user());

            return response()->json(['message' => 'Docente de la asignación actualizado; sus evaluaciones y notas se conservan', 'asignacion' => $asignacion], 200);
        }, 'Error al actualizar la asignación');
    }

    public function destroy(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->asignacionService->eliminar($id, $request->user());

            return response()->json(['message' => 'Asignación eliminada exitosamente'], 200);
        }, 'Error al eliminar la asignación');
    }

    /** Guarda de una vez la carga de un docente en un año (crea las marcadas y quita las desmarcadas). */
    public function guardarCarga(Request $request, string $profesorId)
    {
        return $this->responder(function () use ($request, $profesorId) {
            $datos = $request->validate([
                'anio_lectivo_id' => 'required|integer|exists:anios_lectivos,id',
                'asignaciones' => 'present|array',
                'asignaciones.*.seccion_id' => 'required|integer|exists:secciones,id',
                'asignaciones.*.plan_asignatura_id' => 'required|integer|exists:plan_asignaturas,id',
            ], $this->mensajes());

            $resultado = $this->asignacionService->guardarCarga($profesorId, $datos['anio_lectivo_id'], $datos['asignaciones'], $request->user());

            return response()->json(['message' => 'Carga académica guardada exitosamente'] + $resultado, 200);
        }, 'Error al guardar la carga académica');
    }

    /** Carga académica del docente en sesión (solo lectura). */
    public function miCarga(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate(['anio_lectivo_id' => 'nullable|integer|exists:anios_lectivos,id'], $this->mensajes());

            return response()->json($this->asignacionService->cargaDeDocente($request->user(), $datos['anio_lectivo_id'] ?? null), 200);
        }, 'Error al obtener la carga académica');
    }

    private function responder(callable $accion, string $error)
    {
        try {
            return $accion();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Error: registro no encontrado'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => $error], 500);
        }
    }

    private function mensajes(): array
    {
        return [
            'profesor_id.required' => 'El docente es obligatorio.',
            'profesor_id.exists' => 'El docente seleccionado no existe.',
            'plan_asignatura_id.required' => 'La materia es obligatoria.',
            'plan_asignatura_id.exists' => 'La materia seleccionada no existe.',
            'seccion_id.required' => 'La sección es obligatoria.',
            'seccion_id.exists' => 'La sección seleccionada no existe.',
            'anio_lectivo_id.required' => 'El año lectivo es obligatorio.',
            'anio_lectivo_id.exists' => 'El año lectivo seleccionado no existe.',
            'asignaciones.present' => 'Envíe la lista de asignaciones (puede estar vacía).',
            'asignaciones.array' => 'Las asignaciones deben enviarse como una lista.',
            'asignaciones.*.seccion_id.required' => 'Cada asignación necesita una sección.',
            'asignaciones.*.seccion_id.exists' => 'Una de las secciones seleccionadas no existe.',
            'asignaciones.*.plan_asignatura_id.required' => 'Cada asignación necesita una materia.',
            'asignaciones.*.plan_asignatura_id.exists' => 'Una de las materias seleccionadas no existe.',
        ];
    }
}
