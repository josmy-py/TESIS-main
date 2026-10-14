<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Roles de SIGA (tabla roles). Idempotente: se puede ejecutar varias veces sin duplicar.
     */
    public function run(): void
    {
        foreach (User::ROLES as $rol) {
            Rol::firstOrCreate(['nombre' => $rol]);
        }
    }
}
