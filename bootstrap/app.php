<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\TieneRol::class,
        ]);

        // "sesion" acompaña siempre a auth:api: token emitido con la contraseña vigente
        $middleware->group('sesion', [
            \App\Http\Middleware\SesionVigente::class,
        ]);

        // No existe una ruta web "login": en la API no se redirige (se responde 401 JSON)
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // La API siempre responde JSON (sin redirigir a una ruta login que no existe)
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado. Inicie sesión nuevamente.'], 401);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Demasiados intentos. Espere un momento e inténtelo de nuevo.'], 429, $e->getHeaders());
            }
        });
    })->create();
