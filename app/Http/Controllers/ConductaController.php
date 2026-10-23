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

    /** Registra los 5 criterios de conducta de un estudiante en el periodo (una sola vez). */
    public function guardar(Request $request, string $seccionId)
    {
        return $this->responder(function () use ($request, $seccionId) {
            $datos = $request->validate([
                'periodo_escolar_id' => 'required|integer',
                'matricula_id' => 'required|integer',
                'valoraciones' => 'required|array|size:'.count(ConductaService::CRITERIOS),
                'valoraciones.*' => ['required', Rule::in(ConductaService::VALORACIONES)],
            ], [
                'periodo_escolar_id.required' => 'Indique el periodo.',
                'matricula_id.required' => 'Seleccione el estudiante.',
                'valoraciones.required' => 'Valore cada criterio.',
                'valoraciones.size' => 'Valore los '.count(ConductaService::CRITERIOS).' criterios.',
                'valoraciones.*.required' => 'Seleccione la valoración.',
                'valoraciones.*.in' => 'La valoración debe ser '.implode(', ', ConductaService::VALORACIONES).'.',
            ]);
            $seccion = $this->conducta->seccionPropia($request->user(), $seccionId);

            return response()->json(['message' => 'Conducta registrada']
                + $this->conducta->registrar($seccion, $datos['periodo_escolar_id'], $datos['matricula_id'], array_values($datos['valoraciones'])));
        }, 'Error al guardar la conducta', 'Sección no encontrada');
    }
}
