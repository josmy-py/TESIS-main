<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * M2.2: secciones y matrículas pasan a pertenecer a un año lectivo.
 * Las asignaciones heredan el año de su sección (sin columna nueva).
 *
 * Conservación de datos:
 * - secciones.anio, asignaciones.anio y matriculas.anio no se modifican ni se
 *   eliminan durante la transición.
 * - Por cada año calendario válido (4 cifras, 2000-2100) presente en
 *   secciones.anio, matriculas.anio o asignaciones.anio se crea, si no existe,
 *   un año lectivo con
 *   ese nombre, estado "planificado" y SIN fechas: las fechas históricas no se
 *   inventan, y por eso ese año no puede activarse hasta completarlas.
 * - Una sección recibe el año lectivo de su campo anio.
 * - Una matrícula recibe el año lectivo de su campo anio solo si es válido y,
 *   cuando tiene sección, coincide con el año de la sección. Si no coinciden o
 *   el año no es válido, queda en NULL para revisión manual.
 * - Las asignaciones no cambian: si su año no coincide con el de su sección,
 *   o el año aparece solo en asignaciones, el diagnóstico lo identifica.
 * - Lo que queda sin asignar se lista con: php artisan siga:diagnostico-calendario
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
            Schema::table('secciones', function (Blueprint $table) {
                $table->foreignId('anio_lectivo_id')->nullable()->after('anio')->constrained('anios_lectivos');
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('matriculas', function (Blueprint $table) {
                $table->foreignId('anio_lectivo_id')->nullable()->after('anio')->constrained('anios_lectivos');
            });
        });

        $valido = fn (string $anio) => preg_match('/^\d{4}$/', $anio) === 1 && (int) $anio >= 2000 && (int) $anio <= 2100;

        $anios = DB::table('secciones')->pluck('anio')
            ->merge(DB::table('matriculas')->pluck('anio'))
            ->merge(DB::table('asignaciones')->pluck('anio'))
            ->map(fn ($anio) => trim((string) $anio))
            ->filter($valido)
            ->unique()
            ->sort();

        $ids = [];
        foreach ($anios as $anio) {
            $id = DB::table('anios_lectivos')->where('nombre', $anio)->value('id');
            if (! $id) {
                $id = DB::table('anios_lectivos')->insertGetId([
                    'nombre' => $anio, 'estado' => 'planificado', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ids[$anio] = $id;
        }

        foreach (DB::table('secciones')->get(['id', 'anio']) as $seccion) {
            $anio = trim((string) $seccion->anio);
            if (isset($ids[$anio])) {
                DB::table('secciones')->where('id', $seccion->id)->update(['anio_lectivo_id' => $ids[$anio]]);
            }
        }

        $aniosSeccion = DB::table('secciones')->pluck('anio', 'id')->map(fn ($a) => trim((string) $a));
        foreach (DB::table('matriculas')->get(['id', 'anio', 'seccion_id']) as $matricula) {
            $anio = trim((string) $matricula->anio);
            $coincide = $matricula->seccion_id === null || ($aniosSeccion[$matricula->seccion_id] ?? null) === $anio;
            if (isset($ids[$anio]) && $coincide) {
                DB::table('matriculas')->where('id', $matricula->id)->update(['anio_lectivo_id' => $ids[$anio]]);
            }
        }

        $sinAnio = DB::table('secciones')->whereNull('anio_lectivo_id')->count()
            + DB::table('matriculas')->whereNull('anio_lectivo_id')->count();
        if (count($ids) > 0 || $sinAnio > 0) {
            Log::warning('M2.2: se crearon '.count($ids)." año(s) lectivo(s) sin fechas a partir de datos anteriores y {$sinAnio} sección(es) o matrícula(s) quedaron sin año lectivo. Revíselos con: php artisan siga:diagnostico-calendario");
        }
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('matriculas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('anio_lectivo_id');
            });
        });

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('secciones', function (Blueprint $table) {
                $table->dropConstrainedForeignId('anio_lectivo_id');
            });
        });
        // Los años lectivos creados aquí se eliminan al revertir la migración que crea la tabla
    }
};
