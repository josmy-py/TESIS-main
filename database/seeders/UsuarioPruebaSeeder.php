<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsuarioPruebaSeeder extends Seeder
{
    /**
     * Una cuenta por rol para desarrollo, identificables por el dominio
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
            ['email' => 'director@siga.test', 'rol' => User::ROL_DIRECTOR],
            ['email' => 'docente@siga.test', 'rol' => User::ROL_DOCENTE],
            ['email' => 'encargado@siga.test', 'rol' => User::ROL_ENCARGADO],
        ];

        $password = config('app.seed_password');
        $generada = false;
        if (! $password) {
            $password = Str::password(16, symbols: false);
            $generada = true;
        }

        foreach ($cuentas as $cuenta) {
            $user = User::where('email', $cuenta['email'])->first();

            if (! $user) {
                $user = new User([
                    'email' => $cuenta['email'],
                    'password' => Hash::make($password),
                ]);
                $user->asignarRol($cuenta['rol']);
                $user->save();

                if ($generada) {
                    $this->command?->info("Cuenta {$cuenta['email']} creada con contraseña: {$password}");
                }
            }
        }
    }
}
