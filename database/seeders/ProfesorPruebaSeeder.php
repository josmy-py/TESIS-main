<?php

namespace Database\Seeders;

use App\Models\Profesor;
use App\Models\User;
use App\Services\ProfesorService;
use Illuminate\Database\Seeder;

class ProfesorPruebaSeeder extends Seeder
{
    /**
     * Perfil de docente para la cuenta docente@siga.test (UsuarioPruebaSeeder).
     * Idempotente: si la cuenta no existe o ya tiene perfil, no hace nada.
     * No se ejecuta en producción.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('ProfesorPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $user = User::where('email', 'docente@siga.test')->first();

        if (! $user || $user->profesor()->exists() || Profesor::where('dui', $user->dui)->exists()) {
            return;
        }

        Profesor::create([
            'nombre' => $user->nombre,
            'dui' => $user->dui,
            'fecha_nacimiento' => '1988-03-15',
            'codigo' => app(ProfesorService::class)->siguienteCodigo(),
            'telefono' => '7000-0001',
            'direccion' => 'Dirección de prueba',
            'especialidad' => 'Matemática',
            'fecha_contratacion' => '2020-01-15',
            'user_id' => $user->id,
        ]);
    }
}
