<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:DIRECTOR o role:ENCARGADO|DIRECTOR. Se ejecuta después de auth:api.
 */
class TieneRol
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        if (! $request->user()?->hasRole($roles)) {
            return response()->json(['message' => 'No tiene permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
