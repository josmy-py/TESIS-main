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

        if (! $user || $user->profesor()->exists() || Profesor::where('dui', '00000001-8')->exists()) {
            return;
        }

        Profesor::create([
            'nombre' => 'Docente de Prueba',
            'dui' => '00000001-8',
            'fecha_nacimiento' => '1988-03-15',
            'codigo' => app(ProfesorService::class)->siguienteCodigo(),
            'telefono' => '7000-0001',
            'direccion' => 'Dirección de prueba',
            'user_id' => $user->id,
        ]);
    }
}
