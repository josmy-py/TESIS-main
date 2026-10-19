<?php

namespace Tests\Feature\M2;

use App\Models\User;
use App\Support\RespaldoAlineacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * Migración 2026_10_19_000016 (pedido de Josmy del 19/10/2026): el año
 * lectivo pasa de "nombre" al "anio" del diagrama, el periodo usa
 * nombre_periodo y los estudiantes con matrícula aprobada quedan activos.
 */
class AniosPeriodosDiagramaTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private const MIGRACION = '2026_10_19_000016_anios_y_periodos_como_el_diagrama';

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(RespaldoAlineacion::directorio());
        $this->sembrarRoles();
    }

    private function revertir(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::MIGRACION)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }

    public function test_convierte_datos_y_revertir_los_devuelve(): void
    {
        $this->revertir();
        $this->assertTrue(Schema::hasColumn('anios_lectivos', 'nombre'));

        $a = DB::table('anios_lectivos')->insertGetId(['nombre' => '2026', 'estado' => 'cierre']);
        $b = DB::table('anios_lectivos')->insertGetId(['nombre' => 'Año lectivo 2027', 'estado' => 'activo']);
        DB::table('periodo_escolares')->insert(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $b]);

        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $aprobado = $this->estudianteDe($encargado, $catalogos['distrito']);
        $pendiente = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaDe($aprobado, $catalogos, ['anio_lectivo_id' => $b, 'estado' => 'aprobado']);
        $this->matriculaDe($pendiente, $catalogos, ['anio_lectivo_id' => $b, 'estado' => 'pendiente']);
        DB::table('estudiantes')->update(['estado' => 'inscrito']);

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertFalse(Schema::hasColumn('anios_lectivos', 'nombre'));
        $this->assertSame([2026, 2027], DB::table('anios_lectivos')->orderBy('id')->pluck('anio')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('Primer trimestre', DB::table('periodo_escolares')->value('nombre_periodo'));
        $this->assertSame('activo', DB::table('estudiantes')->where('id', $aprobado->id)->value('estado'));
        $this->assertSame('inscrito', DB::table('estudiantes')->where('id', $pendiente->id)->value('estado'));

        // Revertir devuelve los nombres y el estado anteriores
        $this->revertir();
        $this->assertSame(['2026', 'Año lectivo 2027'], DB::table('anios_lectivos')->orderBy('id')->pluck('nombre')->all());
        $this->assertSame('Primer trimestre', DB::table('periodo_escolares')->value('nombre'));
        $this->assertSame('inscrito', DB::table('estudiantes')->where('id', $aprobado->id)->value('estado'));
        Artisan::call('migrate');
    }

    public function test_se_detiene_si_un_nombre_no_es_un_anio(): void
    {
        $this->revertir();
        DB::table('anios_lectivos')->insert([['nombre' => '2026', 'estado' => 'cierre'], ['nombre' => 'Año 2026', 'estado' => 'inactivo'], ['nombre' => 'Próximo', 'estado' => 'inactivo']]);

        try {
            Artisan::call('migrate');
            $this->fail('Debió detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('repite el año 2026', $e->getMessage());
            $this->assertStringContainsString('"Próximo") no contiene un año', $e->getMessage());
        }
        // Nada cambió
        $this->assertTrue(Schema::hasColumn('anios_lectivos', 'nombre'));
        $this->assertFalse(Schema::hasColumn('anios_lectivos', 'anio'));
        $this->assertSame(3, DB::table('anios_lectivos')->count());

        DB::table('anios_lectivos')->where('nombre', 'Año 2026')->update(['nombre' => '2025']);
        DB::table('anios_lectivos')->where('nombre', 'Próximo')->update(['nombre' => '2027']);
        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertSame([2026, 2025, 2027], DB::table('anios_lectivos')->orderBy('id')->pluck('anio')->map(fn ($v) => (int) $v)->all());
    }
}
