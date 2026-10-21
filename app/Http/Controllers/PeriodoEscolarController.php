<?php

namespace App\Http\Controllers;

use App\Models\PeriodoEscolar;
use App\Services\CalendarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Periodos escolares (M2.2). Consultas para cuentas con sesión; edición solo DIRECTOR.
 */
class PeriodoEscolarController extends Controller
{
    public function __construct(private CalendarioService $calendario)
    {
    }

    public function show(string $id)
    {
        return $this->responder(fn () => response()->json(['periodo' => PeriodoEscolar::with('anioLectivo')->findOrFail($id)]), 'Error al obtener el periodo');
    }

    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            PeriodoEscolar::findOrFail($id);

            $datos = $request->validate([
                'nombre' => 'required|string|max:50',
            ], [
                'nombre.required' => 'El nombre del periodo es obligatorio.',
                'nombre.max' => 'El nombre del periodo no debe exceder los 50 caracteres.',
            ]);

            return response()->json([
                'message' => 'Periodo actualizado',
                'periodo' => $this->calendario->actualizarPeriodo($id, $datos),
            ]);
        }, 'Error al actualizar el periodo');
    }

    /** Estado del periodo: pendiente, activo o cerrado (cierre de cada trimestre). */
    public function estado(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            PeriodoEscolar::findOrFail($id);

            $datos = $request->validate([
                'estado' => ['required', Rule::in(PeriodoEscolar::ESTADOS)],
            ], [
                'estado.required' => 'Indique el estado del periodo.',
                'estado.in' => 'El estado debe ser pendiente, activo o cerrado.',
            ]);
            $periodo = $this->calendario->cambiarEstadoPeriodo($id, $datos['estado']);

            return response()->json([
                'message' => "{$periodo->nombre}: ".CalendarioService::textoEstadoPeriodo($periodo->estado),
                'periodo' => $periodo,
            ]);
        }, 'Error al cambiar el estado del periodo');
    }

    public function destroy(string $id)
    {
        return $this->responder(function () use ($id) {
            $this->calendario->eliminarPeriodo($id);

            return response()->json(['message' => 'Periodo eliminado']);
        }, 'Error al eliminar el periodo');
    }

    private function responder(callable $accion, string $error)
    {
        try {
            return $accion();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Periodo no encontrado'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (QueryException $e) {
            report($e);

            // Índices únicos (peticiones simultáneas)
            if ((string) $e->getCode() === '23000' || (string) $e->getCode() === '23505') {
                return response()->json(['message' => 'La operación entra en conflicto con el estado actual. Actualice e intente de nuevo.'], 409);
            }

            return response()->json(['message' => $error], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => $error], 500);
        }
    }
}
