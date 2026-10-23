<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\NotasService;
use Illuminate\Http\Request;

/**
 * M7 Notas (solo DOCENTE, con sus propias asignaciones): estudiantes de la
 * asignación, evaluaciones por periodo y calificaciones (0 a 10, dos decimales).
 */
class NotasController extends Controller
{
    use RespondeErrores;

    public function __construct(private NotasService $notas)
    {
    }

    /** Profesor.obtenerEstudiantes(asignacion), con los periodos del año. */
    public function estudiantes(Request $request, string $asignacionId)
    {
        return $this->responder(function () use ($request, $asignacionId) {
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json([
                'asignacion' => $asignacion,
                'periodos' => $this->notas->periodos($asignacion),
                'estudiantes' => $this->notas->obtenerEstudiantes($asignacion),
            ]);
        }, 'Error al obtener los estudiantes', 'Asignación no encontrada');
    }

    /** Mis estudiantes > Ver: ficha del estudiante de una sección del docente. */
    public function fichaEstudiante(Request $request, string $asignacionId, string $matriculaId)
    {
        return $this->responder(function () use ($request, $asignacionId, $matriculaId) {
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json($this->notas->fichaEstudiante($asignacion, $matriculaId));
        }, 'Error al obtener el estudiante', 'Estudiante no encontrado');
    }

    public function evaluaciones(Request $request, string $asignacionId)
    {
        return $this->responder(function () use ($request, $asignacionId) {
            $datos = $request->validate(['periodo_escolar_id' => 'required|integer'], [
                'periodo_escolar_id.required' => 'Indique el periodo.',
            ]);
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json($this->notas->evaluaciones($asignacion, $datos['periodo_escolar_id']));
        }, 'Error al obtener las evaluaciones', 'Asignación no encontrada');
    }

    /** Evaluacion.crear() con validarDisponibilidad(). */
    public function crearEvaluacion(Request $request, string $asignacionId)
    {
        return $this->responder(function () use ($request, $asignacionId) {
            $request->merge(['nombre' => is_string($request->nombre) ? trim($request->nombre) : $request->nombre]);
            $datos = $request->validate([
                'nombre' => 'required|string|max:50',
                'porcentaje' => 'required|numeric|min:0.01|max:99.99|decimal:0,2',
                'periodo_escolar_id' => 'required|integer',
            ], [
                'nombre.required' => 'El nombre de la evaluación es obligatorio.',
                'nombre.max' => 'El nombre no debe exceder los 50 caracteres.',
                'porcentaje.required' => 'El porcentaje es obligatorio.',
                'porcentaje.numeric' => 'El porcentaje debe ser un número.',
                'porcentaje.min' => 'El porcentaje debe ser mayor que 0.',
                'porcentaje.max' => 'El porcentaje de una evaluación no puede llegar a 100.',
                'porcentaje.decimal' => 'El porcentaje admite como máximo dos decimales.',
                'periodo_escolar_id.required' => 'Indique el periodo.',
            ]);
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json([
                'message' => 'Evaluación creada',
                'evaluacion' => $this->notas->crearEvaluacion($asignacion, $datos),
            ], 201);
        }, 'Error al crear la evaluación', 'Asignación no encontrada');
    }

    public function eliminarEvaluacion(Request $request, string $evaluacionId)
    {
        return $this->responder(function () use ($request, $evaluacionId) {
            $this->notas->eliminarEvaluacion($request->user(), $evaluacionId);

            return response()->json(['message' => 'Evaluación eliminada']);
        }, 'Error al eliminar la evaluación', 'Evaluación no encontrada');
    }

    public function calificaciones(Request $request, string $evaluacionId)
    {
        return $this->responder(
            fn () => response()->json($this->notas->calificaciones($request->user(), $evaluacionId)),
            'Error al obtener las notas',
            'Evaluación no encontrada'
        );
    }

    /** Calificacion.registrar() y Calificacion.actualizar(nota) de una vez. */
    public function guardarCalificaciones(Request $request, string $evaluacionId)
    {
        return $this->responder(function () use ($request, $evaluacionId) {
            $datos = $request->validate([
                'notas' => 'required|array|min:1',
                'notas.*.matricula_id' => 'required|integer|distinct',
                'notas.*.nota' => 'nullable|numeric|min:0|max:'.NotasService::NOTA_MAXIMA.'|decimal:0,2',
            ], [
                'notas.required' => 'No hay notas para guardar.',
                'notas.*.matricula_id.distinct' => 'Un estudiante aparece dos veces.',
                'notas.*.nota.numeric' => 'La nota debe ser un número.',
                'notas.*.nota.min' => 'La nota no puede ser menor que 0.',
                'notas.*.nota.max' => 'La nota no puede ser mayor que 10.',
                'notas.*.nota.decimal' => 'La nota admite como máximo dos decimales.',
            ]);

            $resultado = $this->notas->guardarCalificaciones($request->user(), $evaluacionId, $datos['notas']);

            return response()->json(['message' => 'Notas guardadas'] + $resultado);
        }, 'Error al guardar las notas', 'Evaluación no encontrada');
    }
}
