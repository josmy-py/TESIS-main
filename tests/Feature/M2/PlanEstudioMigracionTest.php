<?php

namespace Tests\Feature\M2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M2.3: la migración del pensum por grado conserva los planes y asignaturas
 * anteriores y se puede revertir.
 */
class PlanEstudioMigracionTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRACION = '2026_10_12_000005_add_grado_to_plan_estudios_table';

    private function revertirM23(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::MIGRACION)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }

    public function test_migracion_conserva_datos_anteriores_y_es_reversible(): void
    {
        $this->revertirM23();
        $this->assertFalse(Schema::hasColumn('plan_estudios', 'grado_id'));

        $especialidad = DB::table('especialidades')->insertGetId(['nombre' => 'General']);
        $plan = DB::table('plan_estudios')->insertGetId([
            'nombre' => 'Plan heredado', 'especialidad_id' => $especialidad, 'estado' => true,
            'fecha_inicio' => '2025-01-15', 'fecha_fin' => '2025-11-30', 'descripcion' => 'X', 'fecha_aprobacion' => '2024-12-01',
        ]);
        DB::table('asignaturas')->insert(['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan]);

        Artisan::call('migrate', ['--force' => true]);

        $fila = DB::table('plan_estudios')->find($plan);
        $this->assertNull($fila->grado_id);
        $this->assertSame($especialidad, (int) $fila->especialidad_id);
        $this->assertSame(1, DB::table('asignaturas')->where('plan_estudio_id', $plan)->count());

        // El mismo nombre ya se admite en otro plan, y la especialidad es opcional
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        $otro = DB::table('plan_estudios')->insertGetId([
            'nombre' => 'Plan Básica', 'grado_id' => $grado, 'especialidad_id' => null, 'estado' => true,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30', 'descripcion' => 'Y', 'fecha_aprobacion' => '2025-12-01',
        ]);
        DB::table('asignaturas')->insert(['nombre' => 'Lenguaje', 'plan_estudio_id' => $otro]);
        $this->assertSame(2, DB::table('asignaturas')->where('nombre', 'Lenguaje')->count());

        // Reversible cuando los datos lo permiten
        DB::table('asignaturas')->where('plan_estudio_id', $otro)->delete();
        DB::table('plan_estudios')->where('id', $otro)->delete();
        $this->revertirM23();
        $this->assertFalse(Schema::hasColumn('plan_estudios', 'grado_id'));
        $this->assertSame(1, DB::table('asignaturas')->count());
    }
}
