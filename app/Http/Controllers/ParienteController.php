<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\ParientesService;
use App\Support\DuiFormato;
use Illuminate\Http\Request;

class ParienteController extends Controller
{
    use RespondeErrores;

    protected ParientesService $parientesService;

    public function __construct(ParientesService $parientesService)
    {
        $this->parientesService = $parientesService;
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $validated = $request->validate([
                'estudiante_id' => 'required|exists:estudiantes,id',
                'padre_id' => 'required|exists:padres,id',
                'parentesco' => 'required|in:Padre,Madre',
            ]);

            $pariente = $this->parientesService->relacionarPadre(
                $validated['estudiante_id'],
                $validated['padre_id'],
                $validated['parentesco'],
                $this->propietarioId()
            );

            return response()->json([
                'message' => 'Pariente relacionado correctamente',
                'pariente' => $pariente,
            ], 201);
        }, 'Error al relacionar el pariente', 'Estudiante o padre no encontrado');
    }

    /** D-E4: vincula por DUI a una persona ya registrada (POST estudiantes/{id}/padres). */
    public function vincular(Request $request, string $estudianteId)
    {
        return $this->responder(function () use ($request, $estudianteId) {
            $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);
            $validated = $request->validate([
                'dui' => 'required|string|max:10',
                'parentesco' => 'required|in:Padre,Madre',
            ], [
                'dui.required' => 'Ingrese el DUI de la persona.',
                'parentesco.required' => 'Indique el parentesco (Padre o Madre).',
                'parentesco.in' => 'El parentesco debe ser Padre o Madre.',
            ]);

            $padre = $this->parientesService->vincularPorDui(
                $estudianteId, $validated['dui'], $validated['parentesco'], $request->user(), $this->propietarioId()
            );

            return response()->json(['message' => 'Padre o madre vinculado correctamente', 'padre' => $padre], 201);
        }, 'Error al vincular al padre o madre', 'Estudiante no encontrado');
    }

    /** D-E7: quita el vínculo, salvo que sea necesario (DELETE estudiantes/{id}/padres/{padre}). */
    public function desvincular(Request $request, string $estudianteId, string $padreId)
    {
        return $this->responder(function () use ($request, $estudianteId, $padreId) {
            $this->parientesService->desvincular($estudianteId, $padreId, $request->user(), $this->propietarioId());

            return response()->json(['message' => 'Vínculo quitado correctamente']);
        }, 'Error al quitar el vínculo', 'Vínculo no encontrado');
    }
}
