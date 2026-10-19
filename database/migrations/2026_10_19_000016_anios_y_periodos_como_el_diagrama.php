<?php

use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de Josmy (19/10/2026), según el diagrama ER:
 * - El año lectivo se identifica por su año, como la columna "anio" del
 *   diagrama (secciones.anio int, "Año lectivo válido"): anios_lectivos.nombre
 *   pasa a anios_lectivos.anio (entero, único). Si un nombre no contiene un año
 *   de cuatro cifras, o dos nombres dan el mismo año, no se cambia nada y se
 *   indica qué filas revisar.
 * - periodo_escolares.nombre pasa a nombre_periodo, como en el diagrama.
 * - Estudiantes con una matrícula aprobada que siguen "inscrito" pasan a
 *   "activo" (regla: al aprobarse la matrícula el estudiante queda activo).
 * Los valores anteriores quedan en storage/app/respaldos/; revertir los devuelve.
 */
return new class extends Migration
{
    private const RESPALDO = 'anios-periodos';

    public function up(): void
    {
        $anios = DB::table('anios_lectivos')->orderBy('id')->get(['id', 'nombre']);
        $nuevos = [];
        $problemas = [];
        foreach ($anios as $fila) {
            $anio = preg_match('/\b(\d{4})\b/', (string) $fila->nombre, $m) ? (int) $m[1] : null;
            if ($anio === null) {
                $problemas[] = "id {$fila->id} (\"{$fila->nombre}\") no contiene un año de cuatro cifras";
            } elseif (in_array($anio, $nuevos, true)) {
                $problemas[] = "id {$fila->id} (\"{$fila->nombre}\") repite el año {$anio}";
            }
            $nuevos[$fila->id] = $anio;
        }
        if ($problemas) {
            throw new RuntimeException('No se cambió nada. Corrija el nombre de estos años lectivos para que sea solo el año (por ejemplo 2027): '.implode('; ', $problemas));
        }

        $activables = DB::table('estudiantes')->where('estado', 'inscrito')
            ->whereIn('id', DB::table('matriculas')->where('estado', 'aprobado')->select('estudiante_id'))
            ->orderBy('id')->pluck('id')->all();

        RespaldoAlineacion::guardar(self::RESPALDO, [
            'anios_lectivos' => $anios->map(fn ($f) => (array) $f)->all(),
            'estudiantes_activados' => $activables,
        ]);

        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
        });
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->integer('anio')->nullable()->after('id');
        });
        foreach ($nuevos as $id => $anio) {
            DB::table('anios_lectivos')->where('id', $id)->update(['anio' => $anio]);
        }
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->dropColumn('nombre');
        });
        Schema::table('anios_lectivos', function (Blueprint $table) {
            // En SQLite (solo pruebas) change() reconstruye la tabla y pierde la
            // restricción de estado; ahí queda admitiendo NULL y la API lo exige
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->integer('anio')->nullable(false)->change();
            }
            $table->unique('anio');
        });

        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->renameColumn('nombre', 'nombre_periodo');
        });

        if ($activables) {
            DB::table('estudiantes')->whereIn('id', $activables)->update(['estado' => 'activo']);
        }
    }

    public function down(): void
    {
        $respaldo = RespaldoAlineacion::leer(self::RESPALDO) ?? [];
        $nombres = collect($respaldo['anios_lectivos'] ?? [])->pluck('nombre', 'id');

        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->renameColumn('nombre_periodo', 'nombre');
        });

        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->string('nombre', 50)->nullable()->after('id');
        });
        foreach (DB::table('anios_lectivos')->get(['id', 'anio']) as $fila) {
            DB::table('anios_lectivos')->where('id', $fila->id)->update(['nombre' => $nombres[$fila->id] ?? (string) $fila->anio]);
        }
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->dropUnique(['anio']);
        });
        Schema::table('anios_lectivos', function (Blueprint $table) {
            $table->dropColumn('anio');
        });
        Schema::table('anios_lectivos', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->string('nombre', 50)->nullable(false)->change();
            }
            $table->unique('nombre');
        });

        if (! empty($respaldo['estudiantes_activados'])) {
            DB::table('estudiantes')->whereIn('id', $respaldo['estudiantes_activados'])->update(['estado' => 'inscrito']);
        }
        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};
