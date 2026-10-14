<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\LibretaService;
use App\Services\NotasService;
use Illuminate\Http\Request;

/**
 * M9 Libreta (solo DOCENTE, con sus asignaciones): boletas de una materia en un
 * periodo. Las notas finales del año se calculan solas al completarse las boletas.
 */
class LibretaController extends Controller
{
    use RespondeErrores;

    public function __construct(private NotasService $notas, private LibretaService $libreta)
    {
    }

    public function show(Request $request, string $asignacionId)
    {
        return $this->responder(function () use ($request, $asignacionId) {
            $datos = $request->validate(['periodo_escolar_id' => 'required|integer'], ['periodo_escolar_id.required' => 'Indique el periodo.']);
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json($this->libreta->estado($asignacion, $datos['periodo_escolar_id']));
        }, 'Error al obtener la libreta', 'Asignación no encontrada');
    }

    /** Boleta.crear() / actualizar() para cada estudiante de la sección. */
    public function generar(Request $request, string $asignacionId)
    {
        return $this->responder(function () use ($request, $asignacionId) {
            $datos = $request->validate(['periodo_escolar_id' => 'required|integer'], ['periodo_escolar_id.required' => 'Indique el periodo.']);
            $asignacion = $this->notas->asignacionPropia($request->user(), $asignacionId);

            return response()->json(['message' => 'Libreta generada'] + $this->libreta->generar($asignacion, $datos['periodo_escolar_id']));
        }, 'Error al generar la libreta', 'Asignación no encontrada');
    }
}
