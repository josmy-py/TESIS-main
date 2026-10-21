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
 * Migración 2026_10_21_000019 (diagrama actualizado por Josmy el 21/10/2026):
 * plan_asignaturas entre planes y asignaturas, asignaciones con
 * plan_asignatura_id, periodo_escolares.nombre y estado activo. No pierde datos
 * y revertir devuelve lo anterior.
 */
class PlanAsignaturasDiagramaTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private const MIGRACION = '2026_10_21_000019_plan_asignaturas_y_periodos_como_el_diagrama';

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
        $this->assertTrue(Schema::hasColumn('asignaturas', 'plan_estudio_id'));
        $this->assertFalse(Schema::hasTable('plan_asignaturas'));

        // Dos planes con "Matemática" (escrita distinto) y una materia propia de cada uno
        $plan = fn ($nombre) => DB::table('plan_estudios')->insertGetId([
            'nombre' => $nombre, 'estado' => true, 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30',
            'descripcion' => 'X', 'fecha_aprobacion' => '2025-12-01',
        ]);
        $basica = $plan('Plan Básica');
        $media = $plan('Plan Media');
        $asignatura = fn ($nombre, $planId) => DB::table('asignaturas')->insertGetId(['nombre' => $nombre, 'plan_estudio_id' => $planId]);
        $matBasica = $asignatura('Matemática', $basica);
        $lenguaje = $asignatura('Lenguaje', $basica);
        $matMedia = $asignatura('matematica', $media);
        $fisica = $asignatura('Física', $media);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        $anio = DB::table('anios_lectivos')->insertGetId(['anio' => 2026, 'estado' => 'activo']);
        $seccion = DB::table('secciones')->insertGetId([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $grado, 'profesor_id' => $profesor, 'anio_lectivo_id' => $anio,
        ]);
        $otraSeccion = DB::table('secciones')->insertGetId([
            'nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $grado, 'profesor_id' => $profesor, 'anio_lectivo_id' => $anio,
        ]);
        $asignacion = fn ($asignaturaId, $seccionId) => DB::table('asignaciones')->insertGetId(['profesor_id' => $profesor, 'asignatura_id' => $asignaturaId, 'seccion_id' => $seccionId]);
        $a1 = $asignacion($matBasica, $seccion);
        $a2 = $asignacion($lenguaje, $seccion);
        $a3 = $asignacion($matMedia, $otraSeccion);
        $p1 = DB::table('periodo_escolares')->insertGetId(['nombre_periodo' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $anio, 'estado' => 'en_curso']);
        $p2 = DB::table('periodo_escolares')->insertGetId(['nombre_periodo' => 'Segundo trimestre', 'nivel' => 2, 'anio_lectivo_id' => $anio, 'estado' => 'pendiente']);

        $this->assertSame(0, Artisan::call('migrate'));

        // "Matemática" y "matematica" son una sola asignatura enlazada a los dos planes
        $this->assertFalse(Schema::hasColumn('asignaturas', 'plan_estudio_id'));
        $this->assertSame(['Física', 'Lenguaje', 'Matemática'], DB::table('asignaturas')->orderBy('nombre')->pluck('nombre')->all());
        $this->assertSame(4, DB::table('plan_asignaturas')->count());
        $this->assertSame(2, DB::table('plan_asignaturas')->where('asignatura_id', $matBasica)->count());
        $this->assertSame(2, DB::table('plan_asignaturas')->where('plan_estudio_id', $media)->count());

        // Cada asignación apunta a la materia de su plan
        $materiaDe = fn ($asignacionId) => DB::table('asignaciones')->join('plan_asignaturas', 'plan_asignaturas.id', '=', 'asignaciones.plan_asignatura_id')
            ->join('asignaturas', 'asignaturas.id', '=', 'plan_asignaturas.asignatura_id')
            ->where('asignaciones.id', $asignacionId)->first(['plan_asignaturas.plan_estudio_id', 'asignaturas.nombre']);
        $this->assertSame([$basica, 'Matemática'], [(int) $materiaDe($a1)->plan_estudio_id, $materiaDe($a1)->nombre]);
        $this->assertSame([$basica, 'Lenguaje'], [(int) $materiaDe($a2)->plan_estudio_id, $materiaDe($a2)->nombre]);
        $this->assertSame([$media, 'Matemática'], [(int) $materiaDe($a3)->plan_estudio_id, $materiaDe($a3)->nombre]);

        // Periodos con nombre y estado activo, como el diagrama
        $this->assertSame(['Primer trimestre', 'activo'], array_values((array) DB::table('periodo_escolares')->where('id', $p1)->first(['nombre', 'estado'])));
        $this->assertSame('pendiente', DB::table('periodo_escolares')->where('id', $p2)->value('estado'));

        // Revertir devuelve las asignaturas, asignaciones y periodos anteriores
        $this->revertir();
        $this->assertFalse(Schema::hasTable('plan_asignaturas'));
        $this->assertSame(
            [[$matBasica, 'Matemática', $basica], [$lenguaje, 'Lenguaje', $basica], [$matMedia, 'matematica', $media], [$fisica, 'Física', $media]],
            DB::table('asignaturas')->orderBy('id')->get()->map(fn ($f) => [(int) $f->id, $f->nombre, (int) $f->plan_estudio_id])->all()
        );
        $this->assertSame([$matBasica, $lenguaje, $matMedia], DB::table('asignaciones')->orderBy('id')->pluck('asignatura_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['en_curso', 'pendiente'], DB::table('periodo_escolares')->orderBy('id')->pluck('estado')->all());
        $this->assertSame('Primer trimestre', DB::table('periodo_escolares')->where('id', $p1)->value('nombre_periodo'));

        $this->assertSame(0, Artisan::call('migrate'));
    }
}
