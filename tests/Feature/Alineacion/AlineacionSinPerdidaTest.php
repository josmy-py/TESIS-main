<?php

namespace Tests\Feature\Alineacion;

use App\Models\User;
use App\Support\RespaldoAlineacion;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * Alineación con el diagrama sobre una base con datos de M0 a M3:
 * - lo que el diagrama conserva no cambia;
 * - lo que se quita queda respaldado (storage/app/respaldos/; en pruebas,
 *   storage/framework/testing/respaldos/);
 * - revertir devuelve la base exactamente como estaba;
 * - las funciones de M1, M2 y M3 siguen respondiendo con esos datos.
 */
class AlineacionSinPerdidaTest extends TestCase
{
    use DatabaseMigrations, DatosM1;

    private const ALINEACION = 5; // migraciones 000007, 000008, 000009 (índice único de M4), 000010 y 000011 (M6.1)

    private const INFRAESTRUCTURA = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions',
    ];

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->limpiarRespaldos();
    }

    protected function tearDown(): void
    {
        $this->limpiarRespaldos();
        parent::tearDown();
    }

    private function limpiarRespaldos(): void
    {
        File::deleteDirectory(RespaldoAlineacion::directorio());
    }

    public function test_alinear_y_revertir_no_pierde_datos_ni_funciones(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::ALINEACION]);
        $this->assertTrue(Schema::hasColumn('grados', 'orden'), 'La base de prueba debe quedar como en M3');

        $this->cargarDatosM0aM3();
        $antes = $this->volcado();

        // 1. Alinear
        $this->assertSame(0, Artisan::call('migrate'));

        // Lo que el diagrama conserva no cambia (salvo los dos cambios de valores previstos)
        $despues = $this->volcado();
        foreach ($despues as $tabla => $filas) {
            if ($tabla === 'notifications') {
                // D-M6 (M6.1): tabla estándar de avisos autorizada; nace vacía
                $this->assertSame([], $filas);
                continue;
            }
            $this->assertArrayHasKey($tabla, $antes, "Tabla nueva: {$tabla}");
            foreach ($filas as $i => $fila) {
                foreach ($fila as $columna => $valor) {
                    $anterior = $antes[$tabla][$i][$columna];
                    if ($tabla === 'anios_lectivos' && $columna === 'estado') {
                        $anterior = ['planificado' => 'inactivo', 'cerrado' => 'cierre'][$anterior] ?? $anterior;
                    }
                    if ($tabla === 'grados' && $columna === 'nombre') {
                        // Nombres anteriores pasan a su nombre oficial del catálogo
                        $anterior = ['2do grado' => 'Segundo grado', 'Parvularia 4' => 'Parvularia 4 años'][$anterior] ?? $anterior;
                    }
                    if ($tabla === 'periodo_escolares' && $columna === 'nivel') {
                        $anterior = $antes[$tabla][$i]['numero'] ?? $anterior;
                    }
                    $this->assertSame($this->normal($anterior), $this->normal($valor), "{$tabla}.{$columna} (fila {$i}) cambió");
                }
            }
            $this->assertCount(count($antes[$tabla]), $filas, "Cambió la cantidad de filas de {$tabla}");
        }
        $this->assertFalse(Schema::hasTable('auditoria_calendario'));

        // Lo que se quita queda respaldado
        $b = RespaldoAlineacion::leer('quitar-agregados');
        $this->assertSame([1, 2, null], array_column($b['grados'], 'orden'));
        $this->assertSame(['Primer grado', '2do grado', 'Parvularia 4'], array_column($b['grados'], 'nombre'));
        $this->assertSame([$this->ids['padre_libre'], $this->ids['padre_vinculado']], array_column($b['padres'], 'id'));
        $this->assertSame(['2026-01-15', '2026-05-01', '2026-09-01'], array_column(array_slice($b['periodo_escolares'], 0, 3), 'fecha_inicio'));
        $this->assertCount(3, $b['auditoria_calendario']);
        $c = RespaldoAlineacion::leer('tipos-y-anio');
        $this->assertSame(['2026', '2026'], array_map('strval', array_column($c['matriculas'], 'anio')));
        $this->assertSame('2026-11-30', $c['notas_anuales'][0]['fecha_cierre']);

        // 2. Las funciones de M1, M2 y M3 siguen respondiendo con los datos migrados
        $this->comprobarFunciones();

        // 3. Revertir devuelve exactamente la base anterior
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => self::ALINEACION]));
        $this->assertSame($this->normalTodo($antes), $this->normalTodo($this->volcado()));

        // 4. Volver a alinear funciona
        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertFalse(Schema::hasColumn('grados', 'orden'));
    }

    public function test_se_detiene_si_un_grado_no_encaja_en_el_catalogo(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::ALINEACION]);
        $orden = DB::table('grados')->insertGetId(['nombre' => 'Parvularia 4', 'orden' => 1]);
        $sinEquivalente = DB::table('grados')->insertGetId(['nombre' => 'Kinder', 'orden' => null]);
        $a = DB::table('grados')->insertGetId(['nombre' => 'Primer grado', 'orden' => null]);
        $b = DB::table('grados')->insertGetId(['nombre' => '1er grado', 'orden' => null]);

        try {
            Artisan::call('migrate');
            $this->fail('La migración debió detenerse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("grados: id {$orden} \"Parvularia 4\" tiene orden 1, pero en el catálogo \"Parvularia 4 años\" tiene orden -2", $e->getMessage());
            $this->assertStringContainsString("grados: id {$sinEquivalente} \"Kinder\" no corresponde a ningún grado del catálogo oficial", $e->getMessage());
            $this->assertStringContainsString("grados: id {$a} e id {$b} corresponden ambos a \"Primer grado\"", $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('grados', 'orden'), 'No se modificó nada');
        $this->assertSame(['Parvularia 4', 'Kinder', 'Primer grado', '1er grado'], DB::table('grados')->orderBy('id')->pluck('nombre')->all());
    }

    public function test_se_detiene_si_una_nota_se_redondearia(): void
    {
        Artisan::call('migrate:rollback', ['--step' => self::ALINEACION]);
        $this->cargarDatosM0aM3();
        DB::table('calificaciones')->update(['nota_obtenida' => 8.125]);

        try {
            Artisan::call('migrate');
            $this->fail('La migración debió detenerse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nota con más de 2 decimales', $e->getMessage());
        }
        $this->assertSame(8.125, (float) DB::table('calificaciones')->value('nota_obtenida'));
    }

    /** Datos como los que dejan M0 a M3 (con las columnas que se van a quitar). */
    private function cargarDatosM0aM3(): void
    {
        $this->sembrarRoles();
        $cat = $this->catalogosBasicos();
        $director = $this->usuario(User::ROL_DIRECTOR, ['email' => 'director@prueba.test']);
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8', 'email' => 'encargado@prueba.test']);
        $otroEncargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000003-4', 'email' => 'otro@prueba.test']);
        $docente = $this->usuario(User::ROL_DOCENTE, ['dui' => '00000002-6', 'email' => 'docente@prueba.test']);
        $this->ids += ['director' => $director->id, 'encargado' => $encargado->id, 'otro' => $otroEncargado->id, 'docente' => $docente->id];

        $g1 = DB::table('grados')->insertGetId(['nombre' => 'Primer grado', 'orden' => 1]);
        $g2 = DB::table('grados')->insertGetId(['nombre' => '2do grado', 'orden' => 2]);
        DB::table('grados')->insert(['nombre' => 'Parvularia 4', 'orden' => null]);
        $this->ids['grado1'] = $g1;

        $a2025 = DB::table('anios_lectivos')->insertGetId(['nombre' => '2025', 'fecha_inicio' => '2025-01-15', 'fecha_fin' => '2025-11-30', 'estado' => 'cerrado']);
        $a2026 = DB::table('anios_lectivos')->insertGetId(['nombre' => '2026', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30', 'estado' => 'activo', 'activo_unico' => true]);
        DB::table('anios_lectivos')->insert(['nombre' => '2027', 'fecha_inicio' => null, 'fecha_fin' => null, 'estado' => 'planificado']);
        $this->ids['anio2026'] = $a2026;

        $periodos = [];
        foreach ([[1, 'cerrado', '2026-01-15', '2026-04-30'], [2, 'abierto', '2026-05-01', '2026-08-31'], [3, 'pendiente', '2026-09-01', '2026-11-30']] as [$n, $estado, $ini, $fin]) {
            $periodos[$n] = DB::table('periodo_escolares')->insertGetId([
                'nombre' => "Trimestre {$n}", 'nivel' => $n, 'numero' => $n, 'anio_lectivo_id' => $a2026, 'estado' => $estado,
                'fecha_inicio' => $ini, 'fecha_fin' => $fin, 'abierto_en' => $estado === 'abierto' ? $a2026 : null,
            ]);
        }
        DB::table('periodo_escolares')->insert(['nombre' => 'Trimestre 1', 'nivel' => 1, 'numero' => 1, 'anio_lectivo_id' => $a2025, 'estado' => 'cerrado']);
        DB::table('auditoria_calendario')->insert([
            ['entidad' => 'anio_lectivo', 'entidad_id' => $a2026, 'accion' => 'activar', 'estado_anterior' => 'planificado', 'estado_nuevo' => 'activo', 'motivo' => null, 'user_id' => $director->id, 'created_at' => '2026-01-10 08:00:00', 'updated_at' => '2026-01-10 08:00:00'],
            ['entidad' => 'periodo', 'entidad_id' => $periodos[1], 'accion' => 'reabrir', 'estado_anterior' => 'cerrado', 'estado_nuevo' => 'abierto', 'motivo' => 'Corrección de notas pedida por el docente', 'user_id' => $director->id, 'created_at' => '2026-05-02 09:00:00', 'updated_at' => '2026-05-02 09:00:00'],
            ['entidad' => 'periodo', 'entidad_id' => $periodos[2], 'accion' => 'abrir', 'estado_anterior' => 'pendiente', 'estado_nuevo' => 'abierto', 'motivo' => null, 'user_id' => $director->id, 'created_at' => '2026-05-03 09:00:00', 'updated_at' => '2026-05-03 09:00:00'],
        ]);

        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Docente de Prueba', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'PROF-001',
            'telefono' => '7000-0000', 'direccion' => 'Colonia Escalón', 'user_id' => $docente->id,
            'especialidad' => 'Matemática', 'fecha_contratacion' => '2018-01-10',
        ]);
        $this->ids['profesor'] = $profesor;

        $plan = DB::table('plan_estudios')->insertGetId([
            'nombre' => 'Plan Primer grado', 'estado' => true, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2030-12-31',
            'descripcion' => 'Pensum vigente', 'fecha_aprobacion' => '2025-12-01', 'especialidad_id' => null, 'grado_id' => $g1,
        ]);
        $asignatura = DB::table('asignaturas')->insertGetId(['nombre' => 'Matemática', 'plan_estudio_id' => $plan]);

        $seccion = DB::table('secciones')->insertGetId([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $g1, 'profesor_id' => $profesor,
            'anio_lectivo_id' => $a2026, 'anio' => 2026,
        ]);
        $asignacion = DB::table('asignaciones')->insertGetId(['profesor_id' => $profesor, 'asignatura_id' => $asignatura, 'seccion_id' => $seccion, 'anio' => 2026]);

        $estudiante = $this->estudianteDe($encargado, $cat['distrito']);
        $this->ids['estudiante'] = $estudiante->id;
        $matricula = $this->matriculaDe($estudiante, $cat, ['anio' => '2026', 'anio_lectivo_id' => $a2026, 'seccion_id' => $seccion, 'estado' => 'aprobado']);
        $this->matriculaDe($this->estudianteDe($otroEncargado, $cat['distrito']), $cat, ['anio' => '2026', 'anio_lectivo_id' => $a2026]);

        DB::table('encargados')->insert(['nombre' => 'Tía Responsable', 'parentesco' => 'Tío(a)', 'telefono' => '7111-1111', 'dui' => '00000001-8', 'direccion' => 'Calle 1', 'user_id' => $encargado->id]);
        $padreVinculado = DB::table('padres')->insertGetId(['nombre' => 'Padre Vinculado', 'dui' => '00000004-2', 'telefono' => '7222-2222', 'email' => null, 'user_id' => null, 'registrado_por' => $encargado->id]);
        $padreLibre = DB::table('padres')->insertGetId(['nombre' => 'Madre Sin Vincular', 'dui' => '00000005-0', 'telefono' => null, 'email' => null, 'user_id' => null, 'registrado_por' => $encargado->id]);
        // El orden de inserción define el orden por id del respaldo
        DB::table('padres')->where('id', $padreLibre)->update(['id' => 1000]);
        $this->ids += ['padre_vinculado' => 1001, 'padre_libre' => 1000];
        DB::table('padres')->where('id', $padreVinculado)->update(['id' => 1001]);
        DB::table('parientes')->insert(['parentesco' => 'Padre', 'padre_id' => 1001, 'estudiante_id' => $estudiante->id]);

        $discapacidad = DB::table('discapacidades')->insertGetId(['nombre' => 'Visual']);
        DB::table('expedientes')->insert(['discapacidad_id' => $discapacidad, 'matricula_id' => $matricula]);

        $evaluacion = DB::table('evaluaciones')->insertGetId(['nombre' => 'Prueba 1', 'porcentaje' => 33.33, 'periodo_escolar_id' => $periodos[1], 'asignacion_id' => $asignacion]);
        DB::table('calificaciones')->insert(['nota_obtenida' => 8.5, 'matricula_id' => $matricula, 'evaluacion_id' => $evaluacion]);
        DB::table('boletas')->insert(['promedio' => 8.5, 'matricula_id' => $matricula, 'asignacion_id' => $asignacion, 'periodo_escolar_id' => $periodos[1]]);
        DB::table('conductas')->insert(['descripcion' => 'Respetuoso', 'valoracion' => 'Excelente', 'matricula_id' => $matricula, 'periodo_escolar_id' => $periodos[1]]);
        DB::table('notas_anuales')->insert(['promedio_anual' => 8.5, 'estado' => 'Aprobada', 'matricula_id' => $matricula, 'fecha_cierre' => '2026-11-30']);
    }

    private function comprobarFunciones(): void
    {
        $director = User::find($this->ids['director']);
        $encargado = User::find($this->ids['encargado']);
        $otro = User::find($this->ids['otro']);
        $docente = User::find($this->ids['docente']);

        // M1: sesión y cuentas
        $this->postJson('/api/auth/login', ['email' => 'nadie@prueba.test', 'password' => 'x'])->assertStatus(401);
        $this->como($director)->getJson('/api/usuarios')->assertOk();
        $this->como($docente)->getJson('/api/usuarios')->assertStatus(403);

        // M2.1: geografía y grados (el orden es el del catálogo oficial)
        $this->como($encargado)->getJson('/api/departamentos')->assertOk();
        $grados = collect($this->como($director)->getJson('/api/grados')->assertOk()->json('grados'));
        $this->assertSame([-2, 1, 2], $grados->pluck('orden')->all());
        $this->assertSame(['Parvularia 4 años', 'Primer grado', 'Segundo grado'], $grados->pluck('nombre')->all());

        // M2.2: año activo y periodo abierto se conservan
        $actual = $this->como($encargado)->getJson('/api/anios-lectivos/actual')->assertOk();
        $actual->assertJsonPath('anio_lectivo.id', $this->ids['anio2026'])->assertJsonPath('periodo_abierto.nivel', 2);
        $this->como($director)->postJson("/api/anios-lectivos/{$this->ids['anio2026']}/activar")->assertStatus(409);

        // M2.3: pensum del grado
        $this->como($docente)->getJson("/api/asignaturas?grado_id={$this->ids['grado1']}")->assertOk()->assertJsonFragment(['nombre' => 'Matemática']);

        // M3: docentes
        $this->como($director)->getJson('/api/profesores?buscar=PROF-001')->assertOk()->assertJsonFragment(['codigo' => 'PROF-001', 'especialidad' => 'Matemática']);

        // Familia: el padre vinculado a su estudiante sigue visible para su encargado y no para otro.
        // Cambio previsto: sin registrado_por, un padre que el encargado registró pero nunca
        // vinculó a un estudiante solo lo ve el director (el dato queda en el respaldo).
        $this->como($encargado)->postJson('/api/padres/buscar-dui', ['dui' => '00000004-2'])->assertOk()->assertJsonPath('encontrado', true);
        $this->como($otro)->postJson('/api/padres/buscar-dui', ['dui' => '00000004-2'])->assertStatus(404);
        $this->como($encargado)->postJson('/api/padres/buscar-dui', ['dui' => '00000005-0'])->assertStatus(404);
        $this->como($director)->postJson('/api/padres/buscar-dui', ['dui' => '00000005-0'])->assertOk();
        // El diagnóstico se lo señala al director para que lo vincule
        $this->assertSame([$this->ids['padre_libre']], array_column(app(\App\Services\CalendarioService::class)->diagnostico()['padres_sin_estudiantes'], 'id'));
        $this->como($encargado)->getJson("/api/matriculas/estudiantes/{$this->ids['estudiante']}/padres")->assertOk()->assertJsonFragment(['nombre' => 'Padre Vinculado']);

        // Matrícula: duplicidad por año lectivo
        $this->como($encargado)->postJson('/api/matriculas/duplicidad', ['estudiante_id' => $this->ids['estudiante']])
            ->assertStatus(422)->assertJsonPath('duplicidad', true);
    }

    /** Todas las filas de las tablas de negocio, ordenadas por id. */
    private function volcado(): array
    {
        $tablas = array_column(Schema::getTables(Schema::getConnection()->getDatabaseName()), 'name');
        $tablas = array_diff($tablas, self::INFRAESTRUCTURA, ['sqlite_sequence']);
        sort($tablas);

        $volcado = [];
        foreach ($tablas as $tabla) {
            $columnas = Schema::getColumnListing($tabla);
            sort($columnas);
            $consulta = DB::table($tabla);
            foreach (in_array('id', $columnas) ? ['id'] : array_slice($columnas, 0, 2) as $orden) {
                $consulta->orderBy($orden);
            }
            $volcado[$tabla] = $consulta->get($columnas)->map(fn ($f) => (array) $f)->all();
        }

        return $volcado;
    }

    private function normal($valor)
    {
        if (is_bool($valor)) {
            return (int) $valor;
        }
        if (is_numeric($valor)) {
            return (string) (0 + $valor);
        }

        return $valor;
    }

    private function normalTodo(array $volcado): array
    {
        array_walk_recursive($volcado, fn (&$v) => $v = $this->normal($v));

        return $volcado;
    }
}
