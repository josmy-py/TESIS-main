<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se ejecuta después de auth:api (forma parte del grupo "activo").
 *
 * Cada token lleva la huella de la contraseña con la que se emitió. Si la
 * contraseña se cambió o se restableció después, la huella ya no coincide y
 * el token se rechaza aunque no haya vencido. Un token sin huella (emitido
 * antes de esta corrección) también se rechaza: la persona vuelve a iniciar
 * sesión una vez.
 */
class SesionVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $huella = auth('api')->payload()->get(User::CLAIM_CONTRASENA);

            if (! is_string($huella) || ! hash_equals($user->huellaContrasena(), $huella)) {
                return response()->json([
                    'message' => 'Su sesión ya no es válida porque la contraseña cambió. Inicie sesión nuevamente.',
                    'codigo' => 'sesion_revocada',
                ], 401);
            }
        }

        return $next($request);
    }
}
