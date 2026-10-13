<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * M5: respuestas uniformes. 422 validación, 404 no encontrado (lo ajeno
 * también responde así), 409 regla de negocio y 500 error inesperado.
 */
trait RespondeErrores
{
    protected function responder(callable $accion, string $error, string $noEncontrado = 'Registro no encontrado')
    {
        try {
            return $accion();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => $noEncontrado], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => $error], 500);
        }
    }
}
