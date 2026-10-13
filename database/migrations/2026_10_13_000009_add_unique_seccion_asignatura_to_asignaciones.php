<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 (D-A2, aprobado por Josmy el 13/10/2026): una materia de una sección la
 * imparte un solo docente. Solo agrega un índice único; no agrega columnas.
 *
 * Antes revisa los datos: si ya hay una materia repetida en una sección, se
 * detiene sin borrar nada y pide revisarla con
 * `php artisan siga:diagnostico-asignaciones`.
 */
return new class extends Migration
{
    private const INDICE = 'asignaciones_seccion_asignatura_unique';

    public function up(): void
    {
        $duplicadas = DB::table('asignaciones')
            ->select('seccion_id', 'asignatura_id', DB::raw('COUNT(*) as total'))
            ->groupBy('seccion_id', 'asignatura_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicadas->isNotEmpty()) {
            $lista = $duplicadas->map(fn ($d) => "sección {$d->seccion_id} + materia {$d->asignatura_id} ({$d->total} veces)")->implode('; ');

            throw new RuntimeException("No se creó el índice único de asignaciones: hay materias repetidas en una misma sección ({$lista}). Revíselas con 'php artisan siga:diagnostico-asignaciones' y deje una sola por sección; no se modificó ningún dato.");
        }

        Schema::table('asignaciones', function (Blueprint $table) {
            $table->unique(['seccion_id', 'asignatura_id'], self::INDICE);
        });
    }

    public function down(): void
    {
        // MySQL usa el índice compuesto para la llave foránea de seccion_id:
        // se asegura un índice simple antes de quitarlo
        if (! Schema::hasIndex('asignaciones', ['seccion_id'])) {
            Schema::table('asignaciones', function (Blueprint $table) {
                $table->index('seccion_id');
            });
        }

        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropUnique(self::INDICE);
        });
    }
};
