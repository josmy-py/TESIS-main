<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\ConductaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M8 Conducta (solo DOCENTE, en las secciones donde enseña o es guía):
 * registrar observaciones y seguimiento conductual por periodo.
 */
class ConductaController extends Controller
{
    use RespondeErrores;

    public function __construct(private ConductaService $conducta)
    {
    }

    public function index(Request $request, string $seccionId)
    {
        return $this->responder(function () use ($request, $seccionId) {
            $datos = $request->validate(['periodo_escolar_id' => 'nullable|integer']);
            $seccion = $this->conducta->seccionPropia($request->user(), $seccionId);

            return response()->json($this->conducta->listar($seccion, $datos['periodo_escolar_id'] ?? null));
        }, 'Error al obtener la conducta', 'Sección no encontrada');
    }

    public function guardar(Request $request, string $seccionId)
    {
        return $this->responder(function () use ($request, $seccionId) {
            $datos = $request->validate([
                'periodo_escolar_id' => 'required|integer',
                'conductas' => 'required|array|min:1',
                'conductas.*.matricula_id' => 'required|integer|distinct',
                'conductas.*.valoracion' => ['required', Rule::in(ConductaService::VALORACIONES)],
            ], [
                'periodo_escolar_id.required' => 'Indique el periodo.',
                'conductas.required' => 'No hay conducta para guardar.',
                'conductas.*.matricula_id.distinct' => 'Un estudiante aparece dos veces.',
                'conductas.*.valoracion.required' => 'Seleccione la valoración.',
                'conductas.*.valoracion.in' => 'La valoración debe ser '.implode(', ', ConductaService::VALORACIONES).'.',
            ]);
            $seccion = $this->conducta->seccionPropia($request->user(), $seccionId);

            return response()->json(['message' => 'Conducta guardada']
                + $this->conducta->guardar($seccion, $datos['periodo_escolar_id'], $datos['conductas']));
        }, 'Error al guardar la conducta', 'Sección no encontrada');
    }
}
