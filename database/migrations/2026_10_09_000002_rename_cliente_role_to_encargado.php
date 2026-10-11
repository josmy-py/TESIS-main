<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * M1 (D1): el rol técnico CLIENTE pasa a llamarse ENCARGADO.
 *
 * Se renombra el registro existente en roles, así model_has_roles sigue
 * apuntando al mismo id y cada usuario conserva su rol. Si alguien ya creó
 * ENCARGADO, las asignaciones de CLIENTE se trasladan a él sin duplicarse.
 * En una instalación limpia (sin roles todavía) no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renombrar('CLIENTE', 'ENCARGADO');
    }

    public function down(): void
    {
        $this->renombrar('ENCARGADO', 'CLIENTE');
    }

    private function renombrar(string $origen, string $destino): void
    {
        $tablaRoles = config('permission.table_names.roles', 'roles');
        $tablaAsignaciones = config('permission.table_names.model_has_roles', 'model_has_roles');
        $columnaRol = config('permission.column_names.role_pivot_key') ?: 'role_id';

        $rolOrigen = DB::table($tablaRoles)->where('name', $origen)->where('guard_name', 'api')->first();
        if (! $rolOrigen) {
            return;
        }

        $rolDestino = DB::table($tablaRoles)->where('name', $destino)->where('guard_name', 'api')->first();

        if (! $rolDestino) {
            DB::table($tablaRoles)->where('id', $rolOrigen->id)->update([
                'name' => $destino,
                'updated_at' => now(),
            ]);
        } else {
            $asignaciones = DB::table($tablaAsignaciones)->where($columnaRol, $rolOrigen->id)->get();

            foreach ($asignaciones as $asignacion) {
                $yaTiene = DB::table($tablaAsignaciones)
                    ->where($columnaRol, $rolDestino->id)
                    ->where('model_type', $asignacion->model_type)
                    ->where('model_id', $asignacion->model_id)
                    ->exists();

                if (! $yaTiene) {
                    DB::table($tablaAsignaciones)->insert([
                        $columnaRol => $rolDestino->id,
                        'model_type' => $asignacion->model_type,
                        'model_id' => $asignacion->model_id,
                    ]);
                }
            }

            DB::table($tablaAsignaciones)->where($columnaRol, $rolOrigen->id)->delete();
            DB::table($tablaRoles)->where('id', $rolOrigen->id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
