<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Roles de SIGA. Idempotente: se puede ejecutar varias veces sin duplicar.
     */
    public function run(): void
    {
        foreach (User::ROLES as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
