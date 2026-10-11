<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Límites de intentos de los endpoints públicos de autenticación (M1)
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('registro', fn (Request $request) => Limit::perMinute(5)->by('registro:'.$request->ip()));

        // Reenvío de activación y olvido de contraseña (envían correos)
        RateLimiter::for('correo', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(3)->by('correo:'.$email.'|'.$request->ip()),
                Limit::perMinute(10)->by('correo-ip:'.$request->ip()),
            ];
        });

        // Restablecimiento con el token del correo (no envía correos; admite corregir errores del formulario)
        RateLimiter::for('restablecer', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by('restablecer:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('restablecer-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('activacion', fn (Request $request) => Limit::perMinute(10)->by('activacion:'.$request->ip()));
    }
}
