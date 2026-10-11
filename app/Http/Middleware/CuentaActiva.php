<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se ejecuta después de auth:api. Un token emitido antes de que la cuenta
 * fuera desactivada deja de servir en la siguiente petición.
 */
class CuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->estado !== User::ESTADO_ACTIVO) {
            return response()->json([
                'message' => 'Su cuenta no está activa. Comuníquese con la dirección del centro escolar.',
                'codigo' => $user->estado === User::ESTADO_PENDIENTE ? 'cuenta_pendiente' : 'cuenta_inactiva',
            ], 403);
        }

        return $next($request);
    }
}
