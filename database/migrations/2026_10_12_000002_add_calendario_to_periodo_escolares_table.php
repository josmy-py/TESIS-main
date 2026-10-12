<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2: periodos escolares dentro de un año lectivo.
 *
 * - anio_lectivo_id, numero (orden del periodo en el año), fechas y estado
 *   (pendiente, abierto o cerrado).
 * - abierto_en guarda el id del año solo mientras el periodo está abierto: el
 *   índice único impide dos periodos abiertos en el mismo año.
 * - Datos anteriores: el significado de "nivel" no está documentado, así que
 *   no se copia a "numero" ni se asigna año a los periodos existentes. Quedan
 *   con anio_lectivo_id y numero en NULL, conservando nombre y nivel, y se
 *   listan con: php artisan siga:diagnostico-calendario
 * - "nivel" pasa a admitir NULL porque los periodos nuevos no lo usan.
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    public function up(): void
    {
        // SQLite reconstruye la tabla al alterarla; las claves foráneas que la
        // referencian se suspenden solo durante el cambio de estructura
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->foreignId('anio_lectivo_id')->nullable()->after('id')->constrained('anios_lectivos');
                $table->unsignedTinyInteger('numero')->nullable()->after('anio_lectivo_id');
                $table->date('fecha_inicio')->nullable()->after('nivel');
                $table->date('fecha_fin')->nullable()->after('fecha_inicio');
                $table->string('estado', 20)->default('pendiente')->after('fecha_fin');
                $table->unsignedBigInteger('abierto_en')->nullable()->unique()->after('estado');
                $table->unique(['anio_lectivo_id', 'numero']);
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->integer('nivel')->nullable()->change();
            });
        });

        $pendientes = DB::table('periodo_escolares')->whereNull('anio_lectivo_id')->count();
        if ($pendientes > 0) {
            Log::warning("M2.2: {$pendientes} periodo(s) anteriores quedaron sin año lectivo ni número. Revíselos con: php artisan siga:diagnostico-calendario");
        }
    }

    public function down(): void
    {
        // En MySQL 8 la clave foránea usa el índice único (anio_lectivo_id, numero):
        // se quita primero la clave foránea y después los índices y columnas
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropForeign(['anio_lectivo_id']);
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropUnique(['anio_lectivo_id', 'numero']);
                $table->dropUnique(['abierto_en']);
                $table->dropColumn(['anio_lectivo_id', 'numero', 'fecha_inicio', 'fecha_fin', 'estado', 'abierto_en']);
            });
        });
        // "nivel" queda admitiendo NULL: volver a NOT NULL fallaría con periodos creados por M2.2
    }
};
