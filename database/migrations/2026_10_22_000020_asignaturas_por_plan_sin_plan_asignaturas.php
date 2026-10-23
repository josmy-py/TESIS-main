<?php

use App\Support\NombreUnico;
use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de Josmy (22/10/2026), que se aparta del diagrama del 21/10/2026:
 * - Se quita la tabla intermedia plan_asignaturas. Un plan de estudios tiene
 *   muchas asignaturas: asignaturas.plan_estudio_id (nombre único dentro del plan).
 * - asignaciones.plan_asignatura_id se reemplaza por asignaciones.asignatura_id.
 * Cada fila de plan_asignaturas pasa a ser una asignatura de su plan: la
 * asignatura que estaba en un solo plan conserva su id; si estaba en varios,
 * el primer plan conserva el id y los demás reciben una copia con el mismo
 * nombre. Cada asignación apunta a la asignatura de su plan, así que las
 * evaluaciones, notas y boletas no cambian.
 * Las asignaturas del catálogo que no estaban en ningún plan no pueden quedar
 * sin plan: se guardan en el respaldo (storage/app/respaldos/) y revertir la
 * migración las devuelve, igual que plan_asignaturas.
 */
return new class extends Migration
{
    private const RESPALDO = 'asignaturas-por-plan';

    public function up(): void
    {
        $this->diferirLlavesEnSqlite();

        $asignaturas = DB::table('asignaturas')->orderBy('id')->get();
        $planAsignaturas = DB::table('plan_asignaturas')->orderBy('id')->get();
        $asignaciones = DB::table('asignaciones')->orderBy('id')->get(['id', 'plan_asignatura_id']);

        RespaldoAlineacion::guardar(self::RESPALDO, [
            'asignaturas' => $asignaturas->map(fn ($f) => (array) $f)->all(),
            'plan_asignaturas' => $planAsignaturas->map(fn ($f) => (array) $f)->all(),
            'asignaciones' => $asignaciones->map(fn ($f) => (array) $f)->all(),
        ]);

        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
        });
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_estudio_id')->nullable()->after('nombre');
        });

        // Cada plan_asignatura se convierte en la asignatura de su plan
        $porId = $asignaturas->keyBy('id');
        $usadas = [];
        $asignaturaDe = [];
        foreach ($planAsignaturas as $pa) {
            $asignatura = $porId[$pa->asignatura_id];
            if (! isset($usadas[$asignatura->id])) {
                $usadas[$asignatura->id] = true;
                DB::table('asignaturas')->where('id', $asignatura->id)->update(['plan_estudio_id' => $pa->plan_estudio_id]);
                $asignaturaDe[$pa->id] = $asignatura->id;
            } else {
                $asignaturaDe[$pa->id] = DB::table('asignaturas')->insertGetId([
                    'nombre' => $asignatura->nombre, 'plan_estudio_id' => $pa->plan_estudio_id,
                    'created_at' => $pa->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        }

        // Asignaturas que no estaban en ningún plan (quedan en el respaldo)
        $sinPlan = $asignaturas->pluck('id')->reject(fn ($id) => isset($usadas[$id]))->values()->all();
        if ($sinPlan) {
            DB::table('asignaturas')->whereIn('id', $sinPlan)->delete();
        }

        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('plan_estudio_id')->nullable(false)->change();
            }
            $table->foreign('plan_estudio_id')->references('id')->on('plan_estudios');
            $table->unique(['plan_estudio_id', 'nombre']);
        });

        // asignaciones: plan_asignatura_id -> asignatura_id
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('asignatura_id')->nullable()->after('profesor_id');
        });
        foreach ($asignaciones as $fila) {
            DB::table('asignaciones')->where('id', $fila->id)->update(['asignatura_id' => $asignaturaDe[$fila->plan_asignatura_id]]);
        }
        if (! Schema::hasIndex('asignaciones', ['seccion_id'])) {
            Schema::table('asignaciones', function (Blueprint $table) {
                $table->index('seccion_id');
            });
        }
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropUnique('asignaciones_seccion_plan_asignatura_unique');
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropForeign(['plan_asignatura_id']);
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropColumn('plan_asignatura_id');
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('asignatura_id')->nullable(false)->change();
            }
            $table->foreign('asignatura_id')->references('id')->on('asignaturas');
            $table->unique(['seccion_id', 'asignatura_id'], 'asignaciones_seccion_asignatura_unique');
        });

        Schema::dropIfExists('plan_asignaturas');
    }

    public function down(): void
    {
        $this->diferirLlavesEnSqlite();

        $respaldo = RespaldoAlineacion::leer(self::RESPALDO) ?? [];
        $catalogo = collect($respaldo['asignaturas'] ?? [])->keyBy('id');
        $enlacesAnteriores = collect($respaldo['plan_asignaturas'] ?? []);

        Schema::create('plan_asignaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_estudio_id')->constrained('plan_estudios');
            $table->foreignId('asignatura_id')->constrained('asignaturas');
            $table->timestamps();
            $table->unique(['plan_estudio_id', 'asignatura_id']);
        });

        // Asignatura del catálogo por cada nombre: la que ya estaba en el catálogo, si no la de menor id
        $asignaturas = DB::table('asignaturas')->orderBy('id')->get();
        $porNombre = [];
        foreach ($asignaturas->sortBy(fn ($f) => [$catalogo->has($f->id) ? 0 : 1, $f->id]) as $fila) {
            $porNombre[NombreUnico::clave($fila->nombre)] ??= $fila->id;
        }

        $planAsignatura = [];
        foreach ($asignaturas as $fila) {
            $conservada = $porNombre[NombreUnico::clave($fila->nombre)];
            $par = $fila->plan_estudio_id.'-'.$conservada;
            if (isset($planAsignatura[$par])) {
                continue;
            }
            $anterior = $enlacesAnteriores->first(fn ($e) => (int) $e['plan_estudio_id'] === (int) $fila->plan_estudio_id
                && (int) $e['asignatura_id'] === (int) $conservada);
            $planAsignatura[$par] = DB::table('plan_asignaturas')->insertGetId(array_filter([
                'id' => $anterior['id'] ?? null,
                'plan_estudio_id' => $fila->plan_estudio_id,
                'asignatura_id' => $conservada,
                'created_at' => $anterior['created_at'] ?? $fila->created_at ?? now(),
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }
        $enlaceDe = $asignaturas->mapWithKeys(fn ($f) => [$f->id => $planAsignatura[$f->plan_estudio_id.'-'.$porNombre[NombreUnico::clave($f->nombre)]]]);

        Schema::table('asignaciones', function (Blueprint $table) {
            $table->foreignId('plan_asignatura_id')->nullable()->after('profesor_id')->constrained('plan_asignaturas');
        });
        foreach (DB::table('asignaciones')->get(['id', 'asignatura_id']) as $fila) {
            DB::table('asignaciones')->where('id', $fila->id)->update(['plan_asignatura_id' => $enlaceDe[$fila->asignatura_id]]);
        }
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropUnique('asignaciones_seccion_asignatura_unique');
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropForeign(['asignatura_id']);
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->dropColumn('asignatura_id');
        });
        Schema::table('asignaciones', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('plan_asignatura_id')->nullable(false)->change();
            }
            $table->unique(['seccion_id', 'plan_asignatura_id'], 'asignaciones_seccion_plan_asignatura_unique');
        });

        // Las copias por plan vuelven a ser una sola asignatura del catálogo
        $copias = $asignaturas->pluck('id')->reject(fn ($id) => in_array($id, $porNombre))->values()->all();
        if ($copias) {
            DB::table('asignaturas')->whereIn('id', $copias)->delete();
        }

        if (! Schema::hasIndex('asignaturas', ['plan_estudio_id'])) {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->index('plan_estudio_id');
            });
        }
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropUnique(['plan_estudio_id', 'nombre']);
        });
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropForeign(['plan_estudio_id']);
        });
        if (Schema::hasIndex('asignaturas', ['plan_estudio_id'])) {
            Schema::table('asignaturas', function (Blueprint $table) {
                $table->dropIndex(['plan_estudio_id']);
            });
        }
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropColumn('plan_estudio_id');
        });

        // Asignaturas del catálogo que no estaban en ningún plan
        $nombres = DB::table('asignaturas')->pluck('nombre')->map(fn ($n) => NombreUnico::clave($n))->all();
        foreach ($catalogo as $id => $fila) {
            if (! DB::table('asignaturas')->where('id', $id)->exists() && ! in_array(NombreUnico::clave($fila['nombre']), $nombres, true)) {
                DB::table('asignaturas')->insert($fila);
                $nombres[] = NombreUnico::clave($fila['nombre']);
            }
        }

        Schema::table('asignaturas', function (Blueprint $table) {
            $table->unique('nombre');
        });

        RespaldoAlineacion::archivar(self::RESPALDO);
    }

    // En SQLite (solo pruebas) cambiar una llave foránea reconstruye la tabla; las
    // llaves se comprueban al terminar la transacción, cuando ya está completa
    private function diferirLlavesEnSqlite(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }
};
