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
 * Migración 2026_10_22_000020 (pedido de Josmy, 22/10/2026): sin
 * plan_asignaturas, cada asignatura pertenece a un plan y las asignaciones
 * usan asignatura_id. No pierde datos y revertir devuelve lo anterior.
 */
class AsignaturasPorPlanTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(RespaldoAlineacion::directorio());
        $this->sembrarRoles();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(RespaldoAlineacion::directorio());
        parent::tearDown();
    }

    public function test_quita_plan_asignaturas_sin_perder_datos_y_se_puede_revertir(): void
    {
        // Estado anterior: el de la migración 000019
        Artisan::call('migrate:rollback', ['--step' => 2]);
        $this->assertTrue(Schema::hasTable('plan_asignaturas'));

        $plan = fn ($nombre) => DB::table('plan_estudios')->insertGetId([
            'nombre' => $nombre, 'estado' => true, 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30',
            'descripcion' => 'X', 'fecha_aprobacion' => '2025-12-01',
        ]);
        $basica = $plan('Plan Básica');
        $media = $plan('Plan Media');
        $asignatura = fn ($nombre) => DB::table('asignaturas')->insertGetId(['nombre' => $nombre]);
        $matematica = $asignatura('Matemática');
        $lenguaje = $asignatura('Lenguaje');
        $ingles = $asignatura('Inglés'); // en el catálogo, sin plan
        $enlace = fn ($planId, $asignaturaId) => DB::table('plan_asignaturas')->insertGetId(['plan_estudio_id' => $planId, 'asignatura_id' => $asignaturaId]);
        $matBasica = $enlace($basica, $matematica);
        $lenBasica = $enlace($basica, $lenguaje);
        $matMedia = $enlace($media, $matematica);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        $anio = DB::table('anios_lectivos')->insertGetId(['anio' => 2026, 'estado' => 'activo']);
        $seccion = fn ($nombre) => DB::table('secciones')->insertGetId([
            'nombre' => $nombre, 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $grado, 'profesor_id' => $profesor, 'anio_lectivo_id' => $anio,
        ]);
        $a = $seccion('A');
        $b = $seccion('B');
        $asignacion = fn ($enlaceId, $seccionId) => DB::table('asignaciones')->insertGetId(['profesor_id' => $profesor, 'plan_asignatura_id' => $enlaceId, 'seccion_id' => $seccionId]);
        $a1 = $asignacion($matBasica, $a);
        $a2 = $asignacion($lenBasica, $a);
        $a3 = $asignacion($matMedia, $b);
        $periodo = DB::table('periodo_escolares')->insertGetId(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $anio, 'estado' => 'activo']);
        $evaluacion = DB::table('evaluaciones')->insertGetId(['nombre' => 'Examen', 'porcentaje' => 40, 'periodo_escolar_id' => $periodo, 'asignacion_id' => $a3]);

        $this->assertSame(0, Artisan::call('migrate'));

        // Sin tabla intermedia: cada asignatura es de un plan
        $this->assertFalse(Schema::hasTable('plan_asignaturas'));
        $this->assertFalse(Schema::hasColumn('asignaciones', 'plan_asignatura_id'));
        $filas = DB::table('asignaturas')->orderBy('id')->get()->map(fn ($f) => [(int) $f->id, $f->nombre, (int) $f->plan_estudio_id])->all();
        $copia = (int) DB::table('asignaturas')->where('plan_estudio_id', $media)->value('id');
        $this->assertSame([[$matematica, 'Matemática', $basica], [$lenguaje, 'Lenguaje', $basica], [$copia, 'Matemática', $media]], $filas);

        // Las asignaciones conservan su id y apuntan a la asignatura de su plan
        $this->assertSame([$a1 => $matematica, $a2 => $lenguaje, $a3 => $copia],
            DB::table('asignaciones')->orderBy('id')->pluck('asignatura_id', 'id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($a3, (int) DB::table('evaluaciones')->where('id', $evaluacion)->value('asignacion_id'));

        // La asignatura sin plan queda en el respaldo
        $respaldo = RespaldoAlineacion::leer('asignaturas-por-plan');
        $this->assertContains('Inglés', array_column($respaldo['asignaturas'], 'nombre'));
        $this->assertCount(3, $respaldo['plan_asignaturas']);

        // Revertir devuelve plan_asignaturas, el catálogo y las asignaciones como estaban
        Artisan::call('migrate:rollback', ['--step' => 2]);
        $this->assertSame(
            [[$matematica, 'Matemática'], [$lenguaje, 'Lenguaje'], [$ingles, 'Inglés']],
            DB::table('asignaturas')->orderBy('id')->get()->map(fn ($f) => [(int) $f->id, $f->nombre])->all()
        );
        $this->assertSame(
            [[$matBasica, $basica, $matematica], [$lenBasica, $basica, $lenguaje], [$matMedia, $media, $matematica]],
            DB::table('plan_asignaturas')->orderBy('id')->get()->map(fn ($f) => [(int) $f->id, (int) $f->plan_estudio_id, (int) $f->asignatura_id])->all()
        );
        $this->assertSame([$a1 => $matBasica, $a2 => $lenBasica, $a3 => $matMedia],
            DB::table('asignaciones')->orderBy('id')->pluck('plan_asignatura_id', 'id')->map(fn ($v) => (int) $v)->all());

        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertSame(3, DB::table('asignaturas')->count());
    }
}
