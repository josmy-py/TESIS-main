<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\ReporteService;
use Illuminate\Http\Request;

/**
 * M12 Reportes de notas y de conducta por sección y periodo (director: todas
 * las secciones; docente: las suyas).
 */
class ReporteController extends Controller
{
    use RespondeErrores;

    public function __construct(private ReporteService $reportes)
    {
    }

    public function notas(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $this->validar($request);
            $seccion = $this->reportes->seccion($request->user(), $datos['seccion_id']);

            return response()->json($this->reportes->notas($request->user(), $seccion, $datos['periodo_escolar_id']));
        }, 'Error al generar el reporte de notas', 'Sección no encontrada');
    }

    public function conducta(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $this->validar($request);
            $seccion = $this->reportes->seccion($request->user(), $datos['seccion_id']);

            return response()->json($this->reportes->conducta($seccion, $datos['periodo_escolar_id']));
        }, 'Error al generar el reporte de conducta', 'Sección no encontrada');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'seccion_id' => 'required|integer',
            'periodo_escolar_id' => 'required|integer',
        ], [
            'seccion_id.required' => 'Seleccione la sección.',
            'periodo_escolar_id.required' => 'Seleccione el periodo.',
        ]);
    }
}
