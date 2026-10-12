<?php

namespace App\Http\Controllers;

use App\Models\PeriodoEscolar;
use App\Services\CalendarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Periodos escolares (M2.2). Consultas para cuentas con sesión; edición y
 * transiciones de estado solo DIRECTOR. Reabrir exige motivo, que queda en el
 * registro de la aplicación (storage/logs).
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
                'nombre.max' => 'El nombre no debe exceder los 50 caracteres.',
            ]);

            return response()->json([
                'message' => 'Periodo actualizado',
                'periodo' => $this->calendario->actualizarPeriodo($id, $datos),
            ]);
        }, 'Error al actualizar el periodo');
    }

    public function abrir(string $id)
    {
        return $this->responder(fn () => response()->json([
            'message' => 'Periodo abierto',
            'periodo' => $this->calendario->abrirPeriodo($id),
        ]), 'Error al abrir el periodo');
    }

    public function cerrar(string $id)
    {
        return $this->responder(fn () => response()->json([
            'message' => 'Periodo cerrado',
            'periodo' => $this->calendario->cerrarPeriodo($id),
        ]), 'Error al cerrar el periodo');
    }

    public function reabrir(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            PeriodoEscolar::findOrFail($id);

            $datos = $request->validate([
                'motivo' => 'required|string|min:10|max:500',
            ], [
                'motivo.required' => 'El motivo de la reapertura es obligatorio.',
                'motivo.min' => 'Describa el motivo con al menos 10 caracteres.',
                'motivo.max' => 'El motivo no debe exceder los 500 caracteres.',
            ]);

            return response()->json([
                'message' => 'Periodo reabierto',
                'periodo' => $this->calendario->reabrirPeriodo($id, trim($datos['motivo'])),
            ]);
        }, 'Error al reabrir el periodo');
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

            // Índices únicos de año activo o periodo abierto (peticiones simultáneas)
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
