<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * M6.1 (aprobado por Josmy el 13/10/2026). No agrega columnas a `matriculas`:
 *
 * - D-M2: `especialidad_id` opcional (Parvularia y Básica no tienen especialidad).
 * - D-M8: `enfermedad_id` opcional (vacío = sin enfermedad registrada; no se
 *   inventa una enfermedad «Ninguna»).
 *   El diccionario de datos (pág. 49) marca ambas solo como FK, sin NOT NULL.
 * - D-M6: tabla estándar `notifications` de Laravel para los avisos de
 *   matrícula aprobada o rechazada (con su motivo). Excepción al diagrama
 *   autorizada por Josmy.
 *
 * Volver atrás exige que ninguna matrícula tenga esos campos vacíos: si las
 * hay, se detiene sin cambiar nada. Los avisos se respaldan antes de quitar la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->unsignedBigInteger('especialidad_id')->nullable()->change();
            $table->unsignedBigInteger('enfermedad_id')->nullable()->change();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $vacias = DB::table('matriculas')->whereNull('especialidad_id')->orWhereNull('enfermedad_id')->count();
        if ($vacias > 0) {
            throw new RuntimeException("No se puede revertir M6.1: {$vacias} matrícula(s) no tienen especialidad o enfermedad (Parvularia, Básica o estudiantes sin enfermedad). No se modificó ningún dato.");
        }

        if (Schema::hasTable('notifications')) {
            $avisos = DB::table('notifications')->orderBy('created_at')->get()->map(fn ($f) => (array) $f)->all();
            if ($avisos) {
                File::ensureDirectoryExists(RespaldoAlineacion::directorio());
                File::put(
                    RespaldoAlineacion::directorio().'/m6-notificaciones-'.date('Ymd-His').'.json',
                    json_encode(['creado' => now()->toDateTimeString(), 'notifications' => $avisos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }
            Schema::drop('notifications');
        }

        Schema::table('matriculas', function (Blueprint $table) {
            $table->unsignedBigInteger('especialidad_id')->nullable(false)->change();
            $table->unsignedBigInteger('enfermedad_id')->nullable(false)->change();
        });
    }
};
