<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsuarioPruebaSeeder extends Seeder
{
    /**
     * Una cuenta activa por rol para desarrollo, identificables por el dominio
     * siga.test. Idempotente: si la cuenta existe no se duplica ni se cambia
     * su contraseña. La contraseña se toma de SIGA_SEED_PASSWORD; si no está
     * definida se genera una aleatoria y se muestra solo en la consola.
     * No se ejecuta en producción.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('UsuarioPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $cuentas = [
            ['email' => 'director@siga.test', 'nombre' => 'Director de Prueba', 'dui' => '00016297-5', 'rol' => User::ROL_DIRECTOR],
            ['email' => 'docente@siga.test', 'nombre' => 'Docente de Prueba', 'dui' => '00000001-8', 'rol' => User::ROL_DOCENTE],
            ['email' => 'encargado@siga.test', 'nombre' => 'Encargado de Prueba', 'dui' => '00000002-6', 'rol' => User::ROL_ENCARGADO],
        ];

        $password = config('app.seed_password');
        $generada = false;
        if (! $password) {
            $password = Str::password(16, symbols: false);
            $generada = true;
        }

        foreach ($cuentas as $cuenta) {
            $user = User::where('email', $cuenta['email'])->first();

            if (! $user && User::where('dui', $cuenta['dui'])->exists()) {
                // No se toca una cuenta real que ya use ese DUI
                $this->command?->warn("Se omite {$cuenta['email']}: el DUI {$cuenta['dui']} ya pertenece a otra cuenta.");
                continue;
            }

            if (! $user) {
                $user = new User([
                    'nombre' => $cuenta['nombre'],
                    'dui' => $cuenta['dui'],
                    'email' => $cuenta['email'],
                    'password' => Hash::make($password),
                ]);
                $user->estado = User::ESTADO_ACTIVO;
                $user->email_verified_at = now();
                $user->save();

                if ($generada) {
                    $this->command?->info("Cuenta {$cuenta['email']} creada con contraseña: {$password}");
                }
            }

            $user->syncRoles([$cuenta['rol']]);
        }
    }
}
