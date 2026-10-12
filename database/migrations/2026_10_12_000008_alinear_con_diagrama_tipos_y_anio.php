<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\RespaldoAlineacion;

/**
 * Alineación con el diagrama ER (revisión del 12/10/2026, grupo C).
 *
 * Ajusta las diferencias que traían las migraciones originales:
 * - tamaños y enums (profesores, encargados, especialidades, secciones,
 *   plan_estudios, conductas, calificaciones, evaluaciones)
 * - profesores.nombre deja de ser único (en el diagrama solo telefono es único)
 * - secciones.anio, matriculas.anio y asignaciones.anio se quitan: el año va en
 *   anio_lectivo_id (secciones y matrículas) y la asignación lo toma de su sección
 * - notas_anuales.fecha_cierre se quita
 *
 * Antes de cambiar nada revisa los datos. Si algún valor no cabe en el tamaño
 * nuevo o se perdería información, se detiene sin modificar la base e indica
 * qué filas revisar. No recorta ni redondea datos existentes.
 * Lo que se quita (anio y fecha_cierre) queda en storage/app/respaldos/ y
 * revertir la migración lo devuelve.
 */
return new class extends Migration
{
    // SQLite no permite suspender las claves foráneas dentro de una transacción
    public $withinTransaction = false;

    private const RESPALDO = 'tipos-y-anio';

    public function up(): void
    {
        $this->revisarDatos();
        RespaldoAlineacion::guardar(self::RESPALDO, [
            'secciones' => RespaldoAlineacion::filas(DB::table('secciones')->whereNotNull('anio'), ['id', 'anio']),
            'matriculas' => RespaldoAlineacion::filas(DB::table('matriculas')->whereNotNull('anio'), ['id', 'anio']),
            'asignaciones' => RespaldoAlineacion::filas(DB::table('asignaciones')->whereNotNull('anio'), ['id', 'anio']),
            'notas_anuales' => RespaldoAlineacion::filas(DB::table('notas_anuales'), ['id', 'fecha_cierre']),
        ]);
        $this->completarAnioLectivo('secciones');
        $this->completarAnioLectivo('matriculas');

        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('profesores', function (Blueprint $table) {
                $table->dropUnique(['nombre']);
            });
            Schema::table('profesores', function (Blueprint $table) {
                $table->string('nombre', 80)->change();
                $table->string('dui', 10)->change();
                $table->string('telefono', 15)->change();
            });

            Schema::table('encargados', function (Blueprint $table) {
                $table->string('nombre', 80)->change();
                $table->string('telefono', 15)->change();
                $table->enum('parentesco', ['Tío(a)', 'Abuelo(a)', 'Hermano(a)', 'Primo(a)'])->change();
            });

            Schema::table('especialidades', function (Blueprint $table) {
                $table->string('nombre', 50)->change();
            });

            Schema::table('secciones', function (Blueprint $table) {
                $table->string('nombre', 10)->change();
            });
            Schema::table('secciones', function (Blueprint $table) {
                $table->dropColumn('anio');
            });
            Schema::table('matriculas', function (Blueprint $table) {
                $table->dropColumn('anio');
            });
            Schema::table('asignaciones', function (Blueprint $table) {
                $table->dropColumn('anio');
            });

            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->string('descripcion', 200)->change();
            });

            Schema::table('conductas', function (Blueprint $table) {
                $table->string('descripcion', 100)->change();
                $table->string('valoracion', 20)->change();
            });

            Schema::table('calificaciones', function (Blueprint $table) {
                $table->decimal('nota_obtenida', 4, 2)->change();
            });

            Schema::table('evaluaciones', function (Blueprint $table) {
                $table->decimal('porcentaje', 4, 2)->change();
            });

            Schema::table('notas_anuales', function (Blueprint $table) {
                $table->dropColumn('fecha_cierre');
            });
        });
    }

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('notas_anuales', function (Blueprint $table) {
                $table->date('fecha_cierre')->nullable()->after('promedio_anual');
            });
            Schema::table('evaluaciones', function (Blueprint $table) {
                $table->decimal('porcentaje', 5, 2)->change();
            });
            Schema::table('calificaciones', function (Blueprint $table) {
                $table->decimal('nota_obtenida', 8, 5)->change();
            });
            Schema::table('conductas', function (Blueprint $table) {
                $table->string('descripcion')->change();
                $table->string('valoracion')->change();
            });
            Schema::table('plan_estudios', function (Blueprint $table) {
                $table->string('descripcion')->change();
            });

            Schema::table('secciones', function (Blueprint $table) {
                $table->year('anio')->nullable()->after('turno');
            });
            Schema::table('matriculas', function (Blueprint $table) {
                $table->string('anio', 4)->nullable()->after('ingreso');
            });
            Schema::table('asignaciones', function (Blueprint $table) {
                $table->year('anio')->nullable()->after('id');
            });
            foreach (['secciones', 'matriculas'] as $tabla) {
                foreach (DB::table($tabla)->whereNotNull('anio_lectivo_id')->get(['id', 'anio_lectivo_id']) as $fila) {
                    $anio = $this->anioCalendario($fila->anio_lectivo_id);
                    if ($anio !== null) {
                        DB::table($tabla)->where('id', $fila->id)->update(['anio' => $anio]);
                    }
                }
            }
            foreach (DB::table('asignaciones')->get(['id', 'seccion_id']) as $fila) {
                DB::table('asignaciones')->where('id', $fila->id)
                    ->update(['anio' => DB::table('secciones')->where('id', $fila->seccion_id)->value('anio')]);
            }
            $this->restaurarRespaldo();
            Schema::table('secciones', function (Blueprint $table) {
                $table->string('nombre', 50)->change();
            });

            Schema::table('especialidades', function (Blueprint $table) {
                $table->string('nombre', 20)->change();
            });

            Schema::table('encargados', function (Blueprint $table) {
                $table->string('nombre', 50)->change();
                $table->string('telefono', 10)->change();
                $table->enum('parentesco', ['Tío(a)', 'Abuelo(a)', 'Hermano(a)', 'Primo(a)', 'Otro'])->change();
            });

            Schema::table('profesores', function (Blueprint $table) {
                $table->string('nombre', 50)->change();
                $table->string('dui', 15)->change();
                $table->string('telefono', 10)->change();
            });
            Schema::table('profesores', function (Blueprint $table) {
                $table->unique('nombre');
            });
        });
    }

    /**
     * Detiene la migración si algún dato no cabe en el formato del diagrama.
     */
    private function revisarDatos(): void
    {
        $problemas = [];
        $largo = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';

        $revisiones = [
            ['secciones', "{$largo}(nombre) > 10", 'nombre de sección con más de 10 caracteres'],
            ['especialidades', "{$largo}(nombre) > 50", 'nombre de especialidad con más de 50 caracteres'],
            ['plan_estudios', "{$largo}(descripcion) > 200", 'descripción de plan con más de 200 caracteres'],
            ['conductas', "{$largo}(descripcion) > 100", 'descripción de conducta con más de 100 caracteres'],
            ['conductas', "{$largo}(valoracion) > 20", 'valoración de conducta con más de 20 caracteres'],
            ['profesores', "{$largo}(dui) > 10", 'DUI de profesor con más de 10 caracteres'],
            ['encargados', "parentesco = 'Otro'", "encargado con parentesco 'Otro' (no está en el diagrama)"],
            ['calificaciones', 'nota_obtenida > 99.99', 'nota mayor que 99,99'],
            ['evaluaciones', 'porcentaje > 99.99', 'porcentaje mayor que 99,99'],
            ['calificaciones', 'nota_obtenida <> ROUND(nota_obtenida, 2)', 'nota con más de 2 decimales (se redondearía)'],
            ['evaluaciones', 'porcentaje <> ROUND(porcentaje, 2)', 'porcentaje con más de 2 decimales (se redondearía)'],
        ];

        foreach ($revisiones as [$tabla, $condicion, $texto]) {
            $ids = DB::table($tabla)->whereRaw($condicion)->orderBy('id')->pluck('id');
            if ($ids->isNotEmpty()) {
                $problemas[] = "{$tabla}: {$texto} (id ".$ids->implode(', ').')';
            }
        }

        // Una asignación con un año distinto al de su sección perdería ese dato
        foreach (DB::table('asignaciones')->get(['id', 'anio', 'seccion_id']) as $asignacion) {
            $seccion = DB::table('secciones')->where('id', $asignacion->seccion_id)->first(['anio', 'anio_lectivo_id']);
            $anioSeccion = $seccion?->anio ?? ($seccion?->anio_lectivo_id ? $this->anioCalendario($seccion->anio_lectivo_id) : null);
            if ($anioSeccion !== null && (string) $anioSeccion !== (string) $asignacion->anio) {
                $problemas[] = "asignaciones: id {$asignacion->id} tiene el año {$asignacion->anio}, pero su sección es de {$anioSeccion}";
            }
        }

        // Secciones y matrículas sin año lectivo: su "anio" debe ser un año
        // válido y, en la matrícula, el mismo de su sección
        foreach (['secciones', 'matriculas'] as $tabla) {
            foreach (DB::table($tabla)->whereNull('anio_lectivo_id')->whereNotNull('anio')->orderBy('id')->get() as $fila) {
                $anio = trim((string) $fila->anio);
                if (! preg_match('/^\d{4}$/', $anio)) {
                    $problemas[] = "{$tabla}: id {$fila->id} tiene el año \"{$anio}\", que no es un año válido";
                    continue;
                }
                if ($tabla === 'matriculas' && $fila->seccion_id) {
                    $seccion = DB::table('secciones')->where('id', $fila->seccion_id)->first(['anio', 'anio_lectivo_id']);
                    $anioSeccion = $seccion?->anio ?? ($seccion?->anio_lectivo_id ? $this->anioCalendario($seccion->anio_lectivo_id) : null);
                    if ($anioSeccion !== null && (string) $anioSeccion !== $anio) {
                        $problemas[] = "matriculas: id {$fila->id} tiene el año {$anio}, pero su sección es de {$anioSeccion}";
                    }
                }
            }
        }

        if ($problemas) {
            throw new RuntimeException("No se alineó la base con el diagrama. Corrija primero estos datos:\n- ".implode("\n- ", $problemas));
        }
    }

    /**
     * Antes de quitar "anio": las filas sin año lectivo lo toman del año
     * lectivo con ese nombre, que se crea inactivo si no existe.
     */
    private function completarAnioLectivo(string $tabla): void
    {
        $filas = DB::table($tabla)->whereNull('anio_lectivo_id')->whereNotNull('anio')->get(['id', 'anio']);

        foreach ($filas as $fila) {
            $nombre = trim((string) $fila->anio);
            if ($nombre === '') {
                continue;
            }

            $anioId = DB::table('anios_lectivos')->where('nombre', $nombre)->value('id')
                ?? DB::table('anios_lectivos')->insertGetId([
                    'nombre' => $nombre,
                    'estado' => 'inactivo',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table($tabla)->where('id', $fila->id)->update(['anio_lectivo_id' => $anioId]);
        }
    }

    /** Devuelve los valores exactos de "anio" y "fecha_cierre" anteriores. */
    private function restaurarRespaldo(): void
    {
        $respaldo = RespaldoAlineacion::leer(self::RESPALDO);
        if ($respaldo === null) {
            return;
        }

        foreach (['secciones', 'matriculas', 'asignaciones'] as $tabla) {
            foreach ($respaldo[$tabla] as $fila) {
                DB::table($tabla)->where('id', $fila['id'])->update(['anio' => $fila['anio']]);
            }
        }
        foreach ($respaldo['notas_anuales'] as $fila) {
            DB::table('notas_anuales')->where('id', $fila['id'])->update(['fecha_cierre' => $fila['fecha_cierre']]);
        }

        RespaldoAlineacion::archivar(self::RESPALDO);
    }

    private function anioCalendario(int $anioLectivoId): ?string
    {
        $anio = DB::table('anios_lectivos')->where('id', $anioLectivoId)->first(['nombre', 'fecha_inicio']);
        if (! $anio) {
            return null;
        }
        if ($anio->fecha_inicio) {
            return substr((string) $anio->fecha_inicio, 0, 4);
        }

        return preg_match('/^\d{4}$/', $anio->nombre) ? $anio->nombre : null;
    }
};
