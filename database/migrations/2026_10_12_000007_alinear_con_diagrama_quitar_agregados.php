<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\CatalogoGrados;
use App\Support\RespaldoAlineacion;

/**
 * Alineación con el diagrama ER (revisión del 12/10/2026, grupo B).
 *
 * Quita lo que se había agregado en M1, M2.1 y M2.2 sin estar en el diagrama:
 * - grados.orden: el orden es el del catálogo cerrado de grados (App\Support\CatalogoGrados).
 *   Los nombres anteriores se cambian a su nombre oficial solo si es inequívoco
 *   ("1er grado" -> "Primer grado"); el nombre anterior queda en el respaldo.
 * - padres.registrado_por
 * - anios_lectivos.activo_unico; estado pasa a enum('activo','inactivo','cierre')
 *   (planificado -> inactivo, cerrado -> cierre)
 * - periodo_escolares.numero, fecha_inicio, fecha_fin y abierto_en
 *   (el número del periodo pasa a "nivel", que sí está en el diagrama).
 *   Se conserva "estado": el documento exige saber si el periodo está cerrado (pág. 69).
 * - tabla auditoria_calendario
 *
 * Antes de quitar nada:
 * - se detiene si algún grado no tiene un nombre oficial inequívoco, si dos
 *   grados corresponden al mismo nombre oficial o si el orden guardado no es el
 *   del catálogo (cambiaría la promoción de grado);
 * - guarda lo que se quita en storage/app/respaldos/ (App\Support\RespaldoAlineacion).
 *   Revertir la migración devuelve esos valores.
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    private const RESPALDO = 'quitar-agregados';

    public function up(): void
    {
        $nombresOficiales = $this->revisarGrados();
        RespaldoAlineacion::guardar(self::RESPALDO, [
            'grados' => RespaldoAlineacion::filas(DB::table('grados'), ['id', 'nombre', 'orden']),
            'padres' => RespaldoAlineacion::filas(DB::table('padres')->whereNotNull('registrado_por'), ['id', 'registrado_por']),
            'anios_lectivos' => RespaldoAlineacion::filas(DB::table('anios_lectivos'), ['id', 'nombre', 'estado', 'activo_unico']),
            'periodo_escolares' => RespaldoAlineacion::filas(DB::table('periodo_escolares'), ['id', 'numero', 'fecha_inicio', 'fecha_fin', 'abierto_en']),
            'auditoria_calendario' => RespaldoAlineacion::filas(DB::table('auditoria_calendario'), ['*']),
        ]);

        foreach ($nombresOficiales as $id => $oficial) {
            DB::table('grados')->where('id', $id)->update(['nombre' => $oficial]);
        }

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('grados', function (Blueprint $table) {
                $table->dropUnique(['orden']);
            });
            Schema::table('grados', function (Blueprint $table) {
                $table->dropColumn('orden');
            });

            Schema::table('padres', function (Blueprint $table) {
                $table->dropConstrainedForeignId('registrado_por');
            });

            // Años lectivos: valores del diagrama
            DB::table('anios_lectivos')->where('estado', 'planificado')->update(['estado' => 'inactivo']);
            DB::table('anios_lectivos')->where('estado', 'cerrado')->update(['estado' => 'cierre']);
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->dropUnique(['activo_unico']);
            });
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->dropColumn('activo_unico');
            });
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->enum('estado', ['activo', 'inactivo', 'cierre'])->default('inactivo')->change();
            });

            // Periodos: el número del periodo se guarda en "nivel"
            DB::table('periodo_escolares')->whereNotNull('numero')->update(['nivel' => DB::raw('numero')]);
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropForeign(['anio_lectivo_id']);
            });
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropUnique(['anio_lectivo_id', 'numero']);
                $table->dropUnique(['abierto_en']);
            });
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropColumn(['numero', 'fecha_inicio', 'fecha_fin', 'abierto_en']);
            });
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->foreign('anio_lectivo_id')->references('id')->on('anios_lectivos');
            });

            Schema::dropIfExists('auditoria_calendario');
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::create('auditoria_calendario', function (Blueprint $table) {
                $table->id();
                $table->string('entidad', 20);
                $table->unsignedBigInteger('entidad_id');
                $table->string('accion', 20);
                $table->string('estado_anterior', 20)->nullable();
                $table->string('estado_nuevo', 20);
                $table->text('motivo')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['entidad', 'entidad_id']);
            });

            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->dropForeign(['anio_lectivo_id']);
            });
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->unsignedTinyInteger('numero')->nullable()->after('anio_lectivo_id');
                $table->date('fecha_inicio')->nullable()->after('nivel');
                $table->date('fecha_fin')->nullable()->after('fecha_inicio');
                $table->unsignedBigInteger('abierto_en')->nullable()->unique()->after('estado');
            });
            DB::table('periodo_escolares')->whereNotNull('anio_lectivo_id')->update(['numero' => DB::raw('nivel')]);
            DB::table('periodo_escolares')->where('estado', 'abierto')->update(['abierto_en' => DB::raw('anio_lectivo_id')]);
            Schema::table('periodo_escolares', function (Blueprint $table) {
                $table->unique(['anio_lectivo_id', 'numero']);
                $table->foreign('anio_lectivo_id')->references('id')->on('anios_lectivos');
            });

            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->string('estado', 20)->default('planificado')->change();
            });
            DB::table('anios_lectivos')->where('estado', 'inactivo')->update(['estado' => 'planificado']);
            DB::table('anios_lectivos')->where('estado', 'cierre')->update(['estado' => 'cerrado']);
            Schema::table('anios_lectivos', function (Blueprint $table) {
                $table->boolean('activo_unico')->nullable()->unique()->after('estado');
            });
            DB::table('anios_lectivos')->where('estado', 'activo')->update(['activo_unico' => true]);

            Schema::table('padres', function (Blueprint $table) {
                $table->foreignId('registrado_por')->nullable()->after('user_id')
                    ->constrained('users')->nullOnDelete();
            });

            Schema::table('grados', function (Blueprint $table) {
                $table->unsignedInteger('orden')->nullable()->unique()->after('nombre');
            });
            foreach (DB::table('grados')->get(['id', 'nombre']) as $grado) {
                // La escala anterior (M2.1) empezaba en 1: Parvularia queda sin orden
                $orden = CatalogoGrados::orden($grado->nombre);
                if ($orden !== null && $orden >= 1 && ! DB::table('grados')->where('orden', $orden)->exists()) {
                    DB::table('grados')->where('id', $grado->id)->update(['orden' => $orden]);
                }
            }

            $this->restaurarRespaldo();
        });
    }

    /**
     * Cada grado debe corresponder sin duda a un nombre del catálogo oficial y
     * conservar su orden. Si no, se detiene sin modificar nada. Devuelve los
     * nombres que hay que cambiar por el oficial [id => nombre oficial].
     */
    private function revisarGrados(): array
    {
        $problemas = [];
        $cambios = [];
        $usados = [];

        foreach (DB::table('grados')->orderBy('id')->get(['id', 'nombre', 'orden']) as $grado) {
            $oficial = CatalogoGrados::equivalente($grado->nombre);
            if ($oficial === null) {
                $problemas[] = "grados: id {$grado->id} \"{$grado->nombre}\" no corresponde a ningún grado del catálogo oficial. "
                    .'Cámbielo por uno de: '.implode(', ', array_keys(CatalogoGrados::GRADOS)).'.';
                continue;
            }
            if ($grado->orden !== null && (int) $grado->orden !== CatalogoGrados::GRADOS[$oficial]) {
                $problemas[] = "grados: id {$grado->id} \"{$grado->nombre}\" tiene orden {$grado->orden}, pero en el catálogo "
                    ."\"{$oficial}\" tiene orden ".CatalogoGrados::GRADOS[$oficial].'.';
            }
            if (isset($usados[$oficial])) {
                $problemas[] = "grados: id {$usados[$oficial]} e id {$grado->id} corresponden ambos a \"{$oficial}\".";
            }
            $usados[$oficial] = $grado->id;
            if ($oficial !== $grado->nombre) {
                $cambios[$grado->id] = $oficial;
            }
        }

        if ($problemas) {
            throw new RuntimeException("No se alineó la base con el diagrama. Corrija primero estos datos:\n- ".implode("\n- ", $problemas));
        }

        return $cambios;
    }

    /** Devuelve los valores exactos que había antes de aplicar la migración. */
    private function restaurarRespaldo(): void
    {
        $respaldo = RespaldoAlineacion::leer(self::RESPALDO);
        if ($respaldo === null) {
            return;
        }

        DB::table('grados')->update(['orden' => null]);
        foreach ($respaldo['grados'] as $fila) {
            DB::table('grados')->where('id', $fila['id'])->update(['nombre' => $fila['nombre'], 'orden' => $fila['orden']]);
        }
        foreach ($respaldo['padres'] as $fila) {
            DB::table('padres')->where('id', $fila['id'])->update(['registrado_por' => $fila['registrado_por']]);
        }
        // Estados, número y periodo abierto se recalculan arriba con los datos actuales;
        // del respaldo solo se recuperan las fechas de los periodos
        foreach ($respaldo['periodo_escolares'] as $fila) {
            DB::table('periodo_escolares')->where('id', $fila['id'])
                ->update(['fecha_inicio' => $fila['fecha_inicio'], 'fecha_fin' => $fila['fecha_fin']]);
        }
        foreach (array_chunk($respaldo['auditoria_calendario'], 200) as $lote) {
            DB::table('auditoria_calendario')->insert($lote);
        }

        RespaldoAlineacion::archivar(self::RESPALDO);
    }
};
