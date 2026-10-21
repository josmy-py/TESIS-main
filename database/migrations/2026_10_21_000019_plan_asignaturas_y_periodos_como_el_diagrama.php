<?php

use App\Support\NombreUnico;
use App\Support\RespaldoAlineacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagrama ER actualizado por Josmy (21/10/2026):
 * - Tabla nueva plan_asignaturas (plan_estudio_id, asignatura_id): una
 *   asignatura puede estar en varios planes de estudio.
 * - asignaturas queda solo con nombre (sin plan_estudio_id). Las asignaturas
 *   con el mismo nombre (sin contar mayúsculas, tildes ni espacios) pasan a ser
 *   una sola, enlazada a cada plan donde estaba.
 * - asignaciones usa plan_asignatura_id en lugar de asignatura_id.
 * - anios_lectivos.anio es de tipo year; inicio_matricula y fin_matricula ya
 *   vienen en el diagrama.
 * - periodo_escolares.nombre_periodo vuelve a llamarse nombre y el estado
 *   en_curso pasa a activo (enum pendiente, activo, cerrado).
 * No se pierde ningún dato: los valores anteriores quedan en
 * storage/app/respaldos/ y revertir la migración los devuelve.
 */
return new class extends Migration
{
    private const RESPALDO = 'plan-asignaturas';

    public function up(): void
    {
        $this->diferirLlavesEnSqlite();

        $asignaturas = DB::table('asignaturas')->orderBy('id')->get(['id', 'nombre', 'plan_estudio_id', 'created_at', 'updated_at']);
        $asignaciones = DB::table('asignaciones')->orderBy('id')->get(['id', 'asignatura_id']);

        RespaldoAlineacion::guardar(self::RESPALDO, [
            'asignaturas' => $asignaturas->map(fn ($f) => (array) $f)->all(),
            'asignaciones' => $asignaciones->map(fn ($f) => (array) $f)->all(),
            'periodos_en_curso' => DB::table('periodo_escolares')->where('estado', 'en_curso')->orderBy('id')->pluck('id')->all(),
        ]);

        // Asignatura que se conserva por cada nombre (la de menor id)
        $conservada = [];
        $porNombre = [];
        foreach ($asignaturas as $fila) {
            $clave = NombreUnico::clave($fila->nombre);
            $porNombre[$clave] ??= $fila->id;
            $conservada[$fila->id] = $porNombre[$clave];
        }

        Schema::create('plan_asignaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_estudio_id')->constrained('plan_estudios');
            $table->foreignId('asignatura_id')->constrained('asignaturas');
            $table->timestamps();
            $table->unique(['plan_estudio_id', 'asignatura_id']);
        });

        $ahora = now();
        $planAsignatura = [];
        foreach ($asignaturas as $fila) {
            $par = $fila->plan_estudio_id.'-'.$conservada[$fila->id];
            if (! isset($planAsignatura[$par])) {
                $planAsignatura[$par] = DB::table('plan_asignaturas')->insertGetId([
                    'plan_estudio_id' => $fila->plan_estudio_id,
                    'asignatura_id' => $conservada[$fila->id],
                    'created_at' => $fila->created_at ?? $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        Schema::table('asignaciones', function (Blueprint $table) {
            $table->foreignId('plan_asignatura_id')->nullable()->after('profesor_id')->constrained('plan_asignaturas');
        });
        $planDe = $asignaturas->pluck('plan_estudio_id', 'id');
        foreach ($asignaciones as $fila) {
            DB::table('asignaciones')->where('id', $fila->id)->update([
                'plan_asignatura_id' => $planAsignatura[$planDe[$fila->asignatura_id].'-'.$conservada[$fila->asignatura_id]],
            ]);
        }

        // MySQL usa el índice compuesto para la llave foránea de seccion_id
        if (! Schema::hasIndex('asignaciones', ['seccion_id'])) {
            Schema::table('asignaciones', function (Blueprint $table) {
                $table->index('seccion_id');
            });
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

        // Asignaturas repetidas por nombre: ya no las usa ningún plan ni asignación
        $repetidas = collect($conservada)->filter(fn ($destino, $id) => $destino !== $id)->keys()->all();
        if ($repetidas) {
            DB::table('asignaturas')->whereIn('id', $repetidas)->delete();
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
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->unique('nombre');
        });

        // anio de tipo year (en SQLite, solo pruebas, sigue siendo entero)
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->year('anio')->change();
            });
        }

        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->renameColumn('nombre_periodo', 'nombre');
        });
        $this->cambiarEstadosPeriodo(['pendiente', 'activo', 'cerrado'], ['en_curso' => 'activo']);
    }

    public function down(): void
    {
        $this->diferirLlavesEnSqlite();

        $respaldo = RespaldoAlineacion::leer(self::RESPALDO) ?? [];

        $this->cambiarEstadosPeriodo(['pendiente', 'en_curso', 'cerrado'], ['activo' => 'en_curso']);
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->renameColumn('nombre', 'nombre_periodo');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->integer('anio')->change();
            });
        }

        // asignaturas vuelve a pertenecer a un plan
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropUnique(['nombre']);
        });
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_estudio_id')->nullable()->after('nombre');
        });

        // Cada plan_asignatura recupera la asignatura que tenía su plan antes de la migración
        $originales = collect($respaldo['asignaturas'] ?? []);
        $origen = [];
        foreach (DB::table('plan_asignaturas')->orderBy('id')->get() as $pa) {
            $asignatura = DB::table('asignaturas')->find($pa->asignatura_id);
            $original = $originales->first(fn ($o) => (int) $o['plan_estudio_id'] === (int) $pa->plan_estudio_id
                && NombreUnico::clave($o['nombre']) === NombreUnico::clave($asignatura->nombre));

            if ($original) {
                if (DB::table('asignaturas')->where('id', $original['id'])->exists()) {
                    DB::table('asignaturas')->where('id', $original['id'])->update(['plan_estudio_id' => $pa->plan_estudio_id]);
                } else {
                    DB::table('asignaturas')->insert($original);
                }
                $origen[$pa->id] = $original['id'];
            } elseif ($asignatura->plan_estudio_id === null || (int) $asignatura->plan_estudio_id === (int) $pa->plan_estudio_id) {
                DB::table('asignaturas')->where('id', $asignatura->id)->update(['plan_estudio_id' => $pa->plan_estudio_id]);
                $origen[$pa->id] = $asignatura->id;
            } else {
                // Asignatura nueva compartida por varios planes: cada plan tiene su copia
                $origen[$pa->id] = DB::table('asignaturas')->insertGetId([
                    'nombre' => $asignatura->nombre, 'plan_estudio_id' => $pa->plan_estudio_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        Schema::table('asignaciones', function (Blueprint $table) {
            $table->unsignedBigInteger('asignatura_id')->nullable()->after('profesor_id');
        });
        $anteriores = collect($respaldo['asignaciones'] ?? [])->pluck('asignatura_id', 'id');
        foreach (DB::table('asignaciones')->get(['id', 'plan_asignatura_id']) as $fila) {
            DB::table('asignaciones')->where('id', $fila->id)->update([
                'asignatura_id' => $origen[$fila->plan_asignatura_id] ?? $anteriores[$fila->id],
            ]);
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
        Schema::dropIfExists('plan_asignaturas');

        // Asignaturas sin plan (agregadas al catálogo y nunca enlazadas) no caben sin plan_estudio_id
        DB::table('asignaturas')->whereNull('plan_estudio_id')->delete();
        Schema::table('asignaturas', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('plan_estudio_id')->nullable(false)->change();
            }
            $table->foreign('plan_estudio_id')->references('id')->on('plan_estudios');
            $table->unique(['plan_estudio_id', 'nombre']);
        });

        Schema::table('asignaciones', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->unsignedBigInteger('asignatura_id')->nullable(false)->change();
            }
            $table->foreign('asignatura_id')->references('id')->on('asignaturas');
            $table->unique(['seccion_id', 'asignatura_id'], 'asignaciones_seccion_asignatura_unique');
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

    /** Cambia los valores del enum de estado de los periodos sin perder el de cada fila. */
    private function cambiarEstadosPeriodo(array $valores, array $equivalencias): void
    {
        Schema::table('periodo_escolares', function (Blueprint $table) use ($valores) {
            $table->enum('estado_nuevo', $valores)->default('pendiente')->after('nivel');
        });
        foreach (DB::table('periodo_escolares')->get(['id', 'estado']) as $fila) {
            DB::table('periodo_escolares')->where('id', $fila->id)
                ->update(['estado_nuevo' => $equivalencias[$fila->estado] ?? $fila->estado]);
        }
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
        Schema::table('periodo_escolares', function (Blueprint $table) {
            $table->renameColumn('estado_nuevo', 'estado');
        });
    }
};
