<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M2.3 (D11): pensum por grado.
 *
 * - plan_estudios.grado_id: cada plan pertenece a un grado y sus asignaturas
 *   forman el pensum de ese grado (la asignación académica lo validará en M4).
 *   Admite NULL solo para los planes ya registrados; la API lo exige.
 * - plan_estudios.especialidad_id pasa a admitir NULL: Parvularia y Básica no
 *   tienen especialidad; Bachillerato sí.
 * - asignaturas.nombre deja de ser único en todo el sistema y pasa a ser único
 *   dentro de cada plan ("Matemática" existe en el plan de cada grado).
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->foreignId('grado_id')->nullable()->after('nombre')->constrained('grados');
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->unsignedBigInteger('especialidad_id')->nullable()->change();
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->unique(['plan_estudio_id', 'nombre']);
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->dropUnique(['nombre']);
            });
        });

        $pendientes = DB::table('plan_estudios')->whereNull('grado_id')->count();
        if ($pendientes > 0) {
            Log::warning("M2.3: {$pendientes} plan(es) de estudio anteriores quedaron sin grado. Asígnelos al editar cada plan; se listan con GET /api/plan-estudios?sin_grado=1");
        }
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->unique('nombre');
            });
        });

        // MySQL usa el índice compuesto para la clave foránea de plan_estudio_id:
        // primero se crea uno propio para poder quitar el compuesto
        if (! Schema::hasIndex('asignaturas', 'asignaturas_plan_estudio_id_index')) {
            Schema::withoutForeignKeyConstraints(function () {
                Schema::table('asignaturas', function (Blueprint $table) {
                    $table->index('plan_estudio_id');
                });
            });
        }

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->dropUnique(['plan_estudio_id', 'nombre']);
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->unsignedBigInteger('especialidad_id')->nullable(false)->change();
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->dropConstrainedForeignId('grado_id');
            });
        });
    }
};
