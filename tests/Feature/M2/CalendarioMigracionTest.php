<?php

namespace Tests\Feature\M2;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.2: migración sobre una base con datos anteriores. Se deshacen las
 * migraciones de M2.2, se cargan datos con los campos antiguos y se vuelven a
 * aplicar.
 */
class CalendarioMigracionTest extends TestCase
{
    use DatabaseMigrations, DatosM1;

    private const PRIMERA = '2026_10_12_000001_create_anios_lectivos_table';

    private function revertirM22(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', self::PRIMERA)->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
    }

    public function test_migracion_conserva_datos_y_solo_asigna_lo_inequivoco(): void
    {
        $this->revertirM22();
        $this->assertFalse(Schema::hasTable('anios_lectivos'));
        $this->assertFalse(Schema::hasColumn('secciones', 'anio_lectivo_id'));

        $this->sembrarRoles();
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $docente = $this->usuario(User::ROL_DOCENTE);
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $asignatura = DB::table('asignaturas')->insertGetId($this->filaAsignatura());

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
            ['anio' => 2025, 'profesor_id' => $profesor, 'asignatura_id' => $asignatura, 'seccion_id' => $s2026],
            ['anio' => 2023, 'profesor_id' => $profesor, 'asignatura_id' => $asignatura, 'seccion_id' => $s2026], // año solo en asignaciones
            ['anio' => 1999, 'profesor_id' => $profesor, 'asignatura_id' => $asignatura, 'seccion_id' => $s2026], // fuera de rango
        ]);

        DB::table('periodo_escolares')->insert([
            ['nombre' => 'Primer trimestre', 'nivel' => 1],
            ['nombre' => 'Anual', 'nivel' => 7],
        ]);

        $antes = $this->camposAntiguos();

        Artisan::call('migrate');

        // Campos antiguos intactos
        $this->assertSame($antes, $this->camposAntiguos());

        // Años creados sin fechas inventadas, en estado planificado
        $anios = DB::table('anios_lectivos')->orderBy('nombre')->get();
        // También se considera asignaciones.anio (2023 solo aparece allí); 1999 queda fuera de rango
        $this->assertSame(['2023', '2024', '2025', '2026'], $anios->pluck('nombre')->all());
        foreach ($anios as $anio) {
            $this->assertNull($anio->fecha_inicio);
            $this->assertNull($anio->fecha_fin);
            $this->assertSame('planificado', $anio->estado);
        }
        $id = $anios->pluck('id', 'nombre');

        $this->assertSame($id['2025'], (int) DB::table('secciones')->where('id', $s2025)->value('anio_lectivo_id'));
        $this->assertSame($id['2026'], (int) DB::table('secciones')->where('id', $s2026)->value('anio_lectivo_id'));
        $this->assertSame($id['2026'], (int) DB::table('matriculas')->where('id', $coincide)->value('anio_lectivo_id'));
        $this->assertSame($id['2024'], (int) DB::table('matriculas')->where('id', $sinSeccion)->value('anio_lectivo_id'));
        $this->assertNull(DB::table('matriculas')->where('id', $distinta)->value('anio_lectivo_id'));
        $this->assertNull(DB::table('matriculas')->where('id', $invalida)->value('anio_lectivo_id'));

        // Periodos anteriores: se conservan nombre y nivel; no se les asigna año ni número
        $periodos = DB::table('periodo_escolares')->orderBy('id')->get();
        $this->assertSame([['Primer trimestre', 1], ['Anual', 7]], $periodos->map(fn ($p) => [$p->nombre, (int) $p->nivel])->all());
        $this->assertSame([null, null], $periodos->pluck('anio_lectivo_id')->all());
        $this->assertSame([null, null], $periodos->pluck('numero')->all());
        $this->assertSame(['pendiente', 'pendiente'], $periodos->pluck('estado')->all());

        // El diagnóstico lo identifica todo, sin modificar nada
        Artisan::call('siga:diagnostico-calendario');
        $salida = Artisan::output();
        $this->assertStringContainsString('Años lectivos sin fechas (no pueden activarse): 4', $salida);
        $this->assertStringContainsString('Años que solo aparecen en asignaciones (sin sección ni matrícula de ese año): 2', $salida);
        $this->assertStringContainsString('Asignaciones cuyo año no corresponde a ningún año lectivo: 1', $salida);
        $this->assertStringContainsString('Periodos anteriores sin año lectivo o sin número (se conservan nombre y nivel): 2', $salida);
        $this->assertStringContainsString('Matrículas sin año lectivo (año inválido o distinto al de su sección): 2', $salida);
        $this->assertStringContainsString('Asignaciones cuyo año no coincide con el de su sección: 3', $salida);
        $this->assertStringContainsString('Secciones sin año lectivo: 0', $salida);
        $this->assertStringContainsString('20x6', $salida);
        $diagnostico = app(\App\Services\CalendarioService::class)->diagnostico();
        $this->assertSame([
            ['anio' => '1999', 'anio_lectivo_id' => null, 'estado' => null, 'asignaciones' => 1],
            ['anio' => '2023', 'anio_lectivo_id' => $id['2023'], 'estado' => 'planificado', 'asignaciones' => 1],
        ], array_map(fn ($f) => ['anio' => $f['anio'], 'anio_lectivo_id' => $f['anio_lectivo_id'] === null ? null : (int) $f['anio_lectivo_id'], 'estado' => $f['estado'], 'asignaciones' => $f['asignaciones']], $diagnostico['anios_solo_en_asignaciones']));
        $this->assertSame(['1999'], array_map(fn ($f) => (string) $f['anio'], $diagnostico['asignaciones_sin_anio_lectivo']));
        $this->assertSame($antes, $this->camposAntiguos());

        // Un periodo anterior no se puede abrir ni editar hasta revisarlo
        $director = $this->usuario(User::ROL_DIRECTOR);
        $this->como($director)->postJson("/api/periodos-escolares/{$periodos[0]->id}/abrir")->assertStatus(409);
        $this->como($director)->deleteJson("/api/periodos-escolares/{$periodos[0]->id}")->assertStatus(409);
        $this->assertSame(2, DB::table('periodo_escolares')->count());

        // Un año migrado no se activa sin fechas; con fechas, sí
        $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/activar")->assertStatus(409);
        $this->como($director)->putJson("/api/anios-lectivos/{$id['2026']}", ['nombre' => '2026', 'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30'])->assertOk();
        $this->como($director)->postJson("/api/anios-lectivos/{$id['2026']}/activar")->assertOk();

        // Revertir y volver a aplicar conserva los datos
        $this->revertirM22();
        $this->assertSame($antes, $this->camposAntiguos());
        $this->assertSame(2, DB::table('periodo_escolares')->count());
        Artisan::call('migrate');
        $this->assertSame(4, DB::table('anios_lectivos')->count());
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

    private function filaAsignatura(): array
    {
        $plan = DB::table('plan_estudios')->insertGetId([
            'nombre' => 'Plan', 'estado' => true, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2026-12-31',
            'descripcion' => 'X', 'fecha_aprobacion' => '2024-12-01', 'especialidad_id' => DB::table('especialidades')->value('id'),
        ]);

        return ['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan];
    }
}
