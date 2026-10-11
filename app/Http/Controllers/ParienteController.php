<?php

namespace App\Http\Controllers;

use App\Services\ParientesService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ParienteController extends Controller
{
    protected ParientesService $parientesService;

    public function __construct(ParientesService $parientesService)
    {
        $this->parientesService = $parientesService;
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'estudiante_id' => 'required|exists:estudiantes,id',
                'padre_id'      => 'required|exists:padres,id',
                'parentesco'    => 'required|in:Padre,Madre',
            ]);

            $pariente = $this->parientesService->relacionarPadre(
                $validated['estudiante_id'],
                $validated['padre_id'],
                $validated['parentesco'],
                $this->propietarioId()
            );

            return response()->json([
                'message'  => 'Pariente relacionado correctamente',
                'pariente' => $pariente
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors'  => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Estudiante o padre no encontrado'
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al relacionar el pariente',
            ], 500);
        }
    }
}
