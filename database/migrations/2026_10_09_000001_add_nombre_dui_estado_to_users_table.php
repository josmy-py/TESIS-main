<?php

use App\Support\DuiFormato;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M1 (D9): datos de la cuenta en users.
 *
 * - nombre y dui quedan nullable en la base de datos para no romper cuentas
 *   históricas sin información suficiente. Para toda cuenta nueva son
 *   obligatorios (lo exige la validación del backend).
 *   Las cuentas pendientes se listan con: php artisan siga:usuarios-sin-dui
 * - Las cuentas que ya existían quedan en estado 'activo' (ya podían iniciar
 *   sesión); las nuevas nacen en 'pendiente'.
 * - nombre y dui se recuperan de profesores, padres o encargados solo cuando
 *   la correspondencia es inequívoca. Nunca se inventan valores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nombre', 80)->nullable()->after('id');
            $table->string('dui', 10)->nullable()->unique()->after('nombre');
            $table->enum('estado', ['pendiente', 'activo', 'inactivo'])->default('pendiente')->after('password');
        });

        // Cuentas existentes: conservan el acceso que ya tenían
        DB::table('users')->update(['estado' => 'activo']);

        $this->recuperarDatosDePerfiles();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['dui']);
            $table->dropColumn(['nombre', 'dui', 'estado']);
        });
    }

    private function recuperarDatosDePerfiles(): void
    {
        $candidatos = [];

        foreach (['profesores', 'padres', 'encargados'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $filas = DB::table($tabla)->whereNotNull('user_id')->get(['user_id', 'nombre', 'dui']);

            foreach ($filas as $fila) {
                $candidatos[$fila->user_id][] = [
                    'nombre' => trim((string) $fila->nombre),
                    'dui' => DuiFormato::normalizar($fila->dui),
                ];
            }
        }

        // DUI usados por más de una cuenta: ambiguos, no se asignan a ninguna
        $duiPorUsuario = [];
        foreach ($candidatos as $userId => $perfiles) {
            $duis = array_values(array_unique(array_filter(array_column($perfiles, 'dui'))));
            if (count($duis) === 1 && DuiFormato::esValido($duis[0])) {
                $duiPorUsuario[$userId] = $duis[0];
            }
        }
        $repetidos = array_keys(array_filter(array_count_values($duiPorUsuario), fn ($n) => $n > 1));

        foreach ($candidatos as $userId => $perfiles) {
            $cambios = [];

            $nombres = array_values(array_unique(array_filter(array_column($perfiles, 'nombre'))));
            if (count($nombres) === 1) {
                $cambios['nombre'] = mb_substr($nombres[0], 0, 80);
            }

            $dui = $duiPorUsuario[$userId] ?? null;
            if ($dui && ! in_array($dui, $repetidos, true)) {
                $cambios['dui'] = $dui;
            }

            if ($cambios) {
                DB::table('users')->where('id', $userId)->update($cambios);
            }
        }

        $pendientes = DB::table('users')->whereNull('dui')->orWhereNull('nombre')->count();
        if ($pendientes > 0) {
            Log::warning("M1: {$pendientes} cuenta(s) quedaron sin nombre o DUI. Revíselas con: php artisan siga:usuarios-sin-dui");
        }
    }
};
