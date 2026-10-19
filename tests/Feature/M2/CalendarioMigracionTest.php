<?php

namespace Tests\Feature\M2;

use App\Models\User;
use App\Support\RespaldoAlineacion;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * Migraciones del calendario (M2.2) y de la alineación con el diagrama sobre
 * una base con datos anteriores: se deshacen, se cargan datos con los campos
 * antiguos y se vuelven a aplicar.
 */
class CalendarioMigracionTest extends TestCase
{
    use DatabaseMigrations, DatosM1;

    private const PRIMERA = '2026_10_12_000001_create_anios_lectivos_table';

    protected function tearDown(): void
    {
        File::deleteDirectory(RespaldoAlineacion::directorio());
        parent::tearDown();
    }

    private function revertirM22(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::PRIMERA)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }

    public function test_migracion_conserva_datos_y_se_detiene_ante_datos_dudosos(): void
    {
        $this->revertirM22();
        $this->assertFalse(Schema::hasTable('anios_lectivos'));
        $this->assertFalse(Schema::hasColumn('secciones', 'anio_lectivo_id'));

        // Cuentas con el esquema anterior (sin rol_id): solo se necesitan sus id
        $cuenta = fn ($email) => User::find(DB::table('users')->insertGetId(['email' => $email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]));
        $catalogos = $this->catalogosBasicos();
        $encargado = $cuenta('encargado@antes.test');
        $docente = $cuenta('docente@antes.test');
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $asignatura = DB::table('asignaturas')->insertGetId($this->filaAsignatura());
        $otraAsignatura = DB::table('asignaturas')->insertGetId($this->filaAsignatura('Matemática'));

        $seccion = fn ($nombre, $anio) => DB::table('secciones')->insertGetId([
            'nombre' => $nombre, 'capacidad' => 30, 'turno' => 'matutino', 'anio' => $anio, 'grado_id' => $grado, 'profesor_id' => $profesor,
        ]);
        $s2025 = $seccion('A', 2025);
        $s2026 = $seccion('B', 2026);

        $matricula = fn ($anio, $seccionId) => $this->matriculaDe(
            $this->estudianteDe($encargado, $catalogos['distrito']), $catalogos, ['anio' => $anio, 'seccion_id' => $seccionId]
        );
        $coincide = $matricula('2026', $s2026);   // asignable
        $distinta = $matricula('2025', $s2026);   // no coincide con su sección
        $invalida = $matricula('20x6', null);     // año inválido
        $sinSeccion = $matricula('2024', null);   // año válido, sin sección

        DB::table('asignaciones')->insert([
            ['anio' => 2026, 'profesor_id' => $profesor, 'asignatura_id' => $asignatura, 'seccion_id' => $s2026],
            ['anio' => 2025, 'profesor_id' => $profesor, 'asignatura_id' => $otraAsignatura, 'seccion_id' => $s2026], // no coincide
        ]);
        $asignacionDistinta = DB::table('asignaciones')->where('anio', 2025)->value('id');

        DB::table('periodo_escolares')->insert([
            ['nombre' => 'Primer trimestre', 'nivel' => 1],
            ['nombre' => 'Anual', 'nivel' => 7],
        ]);

        // 1. Con datos dudosos la alineación se detiene y dice qué revisar
        try {
            Artisan::call('migrate');
            $this->fail('La migración debió detenerse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("asignaciones: id {$asignacionDistinta} tiene el año 2025, pero su sección es de 2026", $e->getMessage());
            $this->assertStringContainsString("matriculas: id {$distinta} tiene el año 2025, pero su sección es de 2026", $e->getMessage());
            $this->assertStringContainsString("matriculas: id {$invalida} tiene el año \"20x6\"", $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('matriculas', 'anio'), 'Los datos dudosos no se tocan');
        $this->assertSame('20x6', DB::table('matriculas')->where('id', $invalida)->value('anio'));

        // 2. Corregidos los datos, la base queda como el diagrama
        DB::table('matriculas')->where('id', $distinta)->update(['anio' => '2026']);
        DB::table('matriculas')->where('id', $invalida)->update(['anio' => '2026']);
        DB::table('asignaciones')->where('id', $asignacionDistinta)->update(['anio' => 2026]);
        $antes = $this->camposAntiguos();

        Artisan::call('migrate');

        foreach (['secciones', 'matriculas', 'asignaciones'] as $tabla) {
            $this->assertFalse(Schema::hasColumn($tabla, 'anio'), "{$tabla}.anio no está en el diagrama");
        }
        $this->assertFalse(Schema::hasColumn('periodo_escolares', 'numero'));

        // Años creados sin fechas inventadas, inactivos
        $anios = DB::table('anios_lectivos')->orderBy('anio')->get();
        $this->assertSame([2024, 2025, 2026], $anios->pluck('anio')->map(fn ($a) => (int) $a)->all());
        foreach ($anios as $anio) {
            $this->assertNull($anio->fecha_inicio);
            $this->assertNull($anio->fecha_fin);
            $this->assertSame('inactivo', $anio->estado);
        }
        $id = $anios->pluck('id', 'anio');

        $this->assertSame($id['2025'], (int) DB::table('secciones')->where('id', $s2025)->value('anio_lectivo_id'));
        $this->assertSame($id['2026'], (int) DB::table('secciones')->where('id', $s2026)->value('anio_lectivo_id'));
        foreach ([$coincide, $distinta, $invalida] as $m) {
            $this->assertSame($id['2026'], (int) DB::table('matriculas')->where('id', $m)->value('anio_lectivo_id'));
        }
        $this->assertSame($id['2024'], (int) DB::table('matriculas')->where('id', $sinSeccion)->value('anio_lectivo_id'));

        // Periodos anteriores: se conservan nombre y nivel; no se les asigna año
        $periodos = DB::table('periodo_escolares')->orderBy('id')->get();
        $this->assertSame([['Primer trimestre', 1], ['Anual', 7]], $periodos->map(fn ($p) => [$p->nombre_periodo, (int) $p->nivel])->all());
        $this->assertSame([null, null], $periodos->pluck('anio_lectivo_id')->all());
        $this->assertFalse(Schema::hasColumn('periodo_escolares', 'estado'));

        // El diagnóstico lo identifica, sin modificar nada
        Artisan::call('siga:diagnostico-calendario');
        $salida = Artisan::output();
        $this->assertStringContainsString('Años lectivos sin fechas (no pueden activarse): 3', $salida);
        $this->assertStringContainsString('Periodos anteriores sin año lectivo o sin número (nivel): 2', $salida);
        $this->assertStringContainsString('Secciones sin año lectivo: 0', $salida);
        $this->assertStringContainsString('Matrículas sin año lectivo: 0', $salida);
        $this->assertStringContainsString('Matrículas con un año lectivo distinto al de su sección: 0', $salida);

        // Un periodo anterior no se puede borrar hasta revisarlo
        $director = $this->usuario(User::ROL_DIRECTOR);
        $this->como($director)->deleteJson("/api/periodos-escolares/{$periodos[0]->id}")->assertStatus(409);
        $this->assertSame(2, DB::table('periodo_escolares')->count());

        // Un año migrado no se activa sin fechas; con fechas, sí
        $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/activar")->assertStatus(409);
        $this->como($director)->putJson("/api/anios-lectivos/{$id['2026']}", ['anio' => 2026, 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30'])->assertOk();
        $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/activar")->assertStatus(409);
        $nuevo = $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/periodos", ['nombre_periodo' => 'Primer trimestre', 'nivel' => 1])->assertCreated()->json('periodo.id');
        $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/activar")->assertOk();

        // 3. Revertir y volver a aplicar conserva los datos
        DB::table('periodo_escolares')->where('id', $nuevo)->delete();
        $this->revertirM22();
        $this->assertSame($antes, $this->camposAntiguos());
        $this->assertSame(2, DB::table('periodo_escolares')->count());
        Artisan::call('migrate');
        $this->assertSame(3, DB::table('anios_lectivos')->count());
    }

    private function camposAntiguos(): array
    {
        return [
            DB::table('secciones')->orderBy('id')->get(['id', 'nombre', 'anio'])->map(fn ($f) => (array) $f)->all(),
            DB::table('matriculas')->orderBy('id')->get(['id', 'anio', 'seccion_id', 'estado'])->map(fn ($f) => (array) $f)->all(),
            DB::table('asignaciones')->orderBy('id')->get(['id', 'anio', 'seccion_id'])->map(fn ($f) => (array) $f)->all(),
            DB::table('periodo_escolares')->orderBy('id')->get(['id', 'nombre', 'nivel'])->map(fn ($f) => (array) $f)->all(),
        ];
    }

    private function filaAsignatura(string $nombre = 'Lenguaje'): array
    {
        $plan = DB::table('plan_estudios')->insertGetId([
            'nombre' => 'Plan', 'estado' => true, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2026-12-31',
            'descripcion' => 'X', 'fecha_aprobacion' => '2024-12-01', 'especialidad_id' => DB::table('especialidades')->value('id'),
        ]);

        return ['nombre' => $nombre, 'plan_estudio_id' => $plan];
    }
}
