<?php

namespace Tests\Feature\M2;

use App\Models\Grado;
use App\Models\User;
use App\Support\OrdenGrado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.1.1: orden académico de los grados y promoción basada en ese orden.
 */
class GradosTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    public function test_crear_y_editar_con_orden(): void
    {
        $id = $this->como($this->director)->postJson('/api/grados', ['nombre' => 'Primer grado', 'orden' => 1])
            ->assertCreated()->assertJsonPath('grado.orden', 1)->json('grado.id');

        $this->como($this->director)->putJson("/api/grados/{$id}", ['nombre' => 'Primer grado', 'orden' => 2])
            ->assertOk()->assertJsonPath('grado.orden', 2);
        $this->assertSame(2, Grado::find($id)->orden);

        // Conservar el mismo orden al editar no choca consigo mismo
        $this->como($this->director)->putJson("/api/grados/{$id}", ['nombre' => '1.º grado', 'orden' => 2])->assertOk();
    }

    public function test_orden_obligatorio_invalido_o_duplicado_se_rechaza(): void
    {
        Grado::create(['nombre' => 'Primer grado', 'orden' => 1]);
        $legado = Grado::create(['nombre' => 'Parvularia 4']);

        foreach ([null, '', 0, -3, 1.5, 'abc', 1000] as $valor) {
            $this->como($this->director)->postJson('/api/grados', ['nombre' => 'Segundo grado', 'orden' => $valor])
                ->assertStatus(422)->assertJsonValidationErrors('orden');
        }
        $this->como($this->director)->postJson('/api/grados', ['nombre' => 'Segundo grado'])
            ->assertStatus(422)->assertJsonValidationErrors('orden');
        $this->como($this->director)->postJson('/api/grados', ['nombre' => 'Segundo grado', 'orden' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('orden')
            ->assertJsonPath('errors.orden.0', 'Ya existe un grado con ese orden académico.');

        // Completar un grado heredado exige el orden
        $this->como($this->director)->putJson("/api/grados/{$legado->id}", ['nombre' => 'Parvularia 4'])
            ->assertStatus(422)->assertJsonValidationErrors('orden');
        $this->assertSame(2, Grado::count());
    }

    public function test_solo_el_director_escribe_y_los_demas_consultan(): void
    {
        $grado = Grado::create(['nombre' => 'Primer grado', 'orden' => 1]);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/grados')->assertOk();
            $this->como($cuenta)->postJson('/api/grados', ['nombre' => 'X', 'orden' => 5])->assertStatus(403);
            $this->como($cuenta)->putJson("/api/grados/{$grado->id}", ['nombre' => 'X', 'orden' => 5])->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/grados/{$grado->id}")->assertStatus(403);
        }
        $this->sinSesion()->getJson('/api/grados')->assertStatus(401);
    }

    public function test_listado_en_orden_academico_con_pendientes_al_final(): void
    {
        // Ids en un orden distinto al académico
        Grado::create(['nombre' => 'Noveno grado', 'orden' => 9]);
        Grado::create(['nombre' => 'Parvularia 5']);
        Grado::create(['nombre' => 'Primer grado', 'orden' => 1]);
        Grado::create(['nombre' => 'Primer año de bachillerato', 'orden' => 10]);

        $this->como($this->director)->getJson('/api/grados')->assertOk()
            ->assertJsonPath('grados.*.nombre', ['Primer grado', 'Noveno grado', 'Primer año de bachillerato', 'Parvularia 5']);
    }

    public function test_promocion_sigue_el_orden_y_no_el_id(): void
    {
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $catalogos = $this->catalogosBasicos();

        // Se crean en desorden: el id de "Segundo" no es el de "Primero" + 1
        $segundo = Grado::create(['nombre' => 'Segundo grado', 'orden' => 2]);
        $tercero = Grado::create(['nombre' => 'Tercer grado', 'orden' => 3]);
        $primero = Grado::create(['nombre' => 'Primer grado', 'orden' => 1]);

        $estudiante = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($estudiante, $catalogos, $primero);

        $validar = fn ($grado) => $this->como($encargado)
            ->postJson("/api/matriculas/validar-grado/{$estudiante->id}/{$grado->id}", ['ingreso' => 'Reingreso']);

        $validar($segundo)->assertOk()->assertJsonPath('permitido', true);
        $validar($tercero)->assertStatus(422)->assertJsonPath('permitido', false)->assertJsonPath('grado_permitido', $segundo->id);
        // Con la regla anterior (id + 1) el grado permitido no habría existido
        $this->assertNull(Grado::find($primero->id + 1));

        // Un hueco en la numeración no impide encontrar el siguiente
        $tercero->update(['orden' => 7]);
        $segundo->update(['orden' => 5]);
        $validar($segundo)->assertOk()->assertJsonPath('permitido', true);
    }

    public function test_promocion_falla_controlada_sin_orden_o_sin_siguiente(): void
    {
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $catalogos = $this->catalogosBasicos();

        $sinOrden = Grado::create(['nombre' => 'Grado heredado']);
        $ultimo = Grado::create(['nombre' => 'Noveno grado', 'orden' => 9]);
        $otro = Grado::create(['nombre' => 'Octavo grado', 'orden' => 8]);

        $a = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($a, $catalogos, $sinOrden);
        $this->como($encargado)->postJson("/api/matriculas/validar-grado/{$a->id}/{$ultimo->id}", ['ingreso' => 'Reingreso'])
            ->assertStatus(422)->assertJsonPath('permitido', false)
            ->assertJsonPath('message', 'No se puede determinar el grado siguiente: el grado anterior no tiene orden académico. El director debe completarlo.');

        $b = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($b, $catalogos, $ultimo);
        $this->como($encargado)->postJson("/api/matriculas/validar-grado/{$b->id}/{$otro->id}", ['ingreso' => 'Reingreso'])
            ->assertStatus(422)->assertJsonPath('permitido', false)->assertJsonPath('grado_permitido', null)
            ->assertJsonPath('message', 'No se puede determinar el grado siguiente: no hay un grado registrado después del grado anterior.');
    }

    public function test_regla_de_inferencia_solo_acepta_nombres_inequivocos(): void
    {
        $casos = [
            'Primer grado' => 1, 'primero grado' => 1, '1.º grado' => 1, '1° Grado' => 1, '1er grado' => 1,
            'Segundo Grado' => 2, '2do grado' => 2, 'Séptimo grado' => 7, 'Setimo grado' => 7, '  NOVENO   grado ' => 9,
            'Primer año de bachillerato' => 10, '2.º año bachillerato' => 11, 'Segundo año de Bachillerato General' => 11,
            'Tercer año de bachillerato' => 12,
            '1er Año' => null, 'Parvularia 4' => null, 'Kinder' => null, 'Décimo grado' => null, 'Grado 1' => null,
            'Cuarto año de bachillerato' => null, 'Primer grado A' => null, '' => null,
        ];

        foreach ($casos as $nombre => $esperado) {
            $this->assertSame($esperado, OrdenGrado::inferir($nombre), "Nombre: '{$nombre}'");
        }
    }

    // Base con grados anteriores a M2.1: se deshace la migración, se cargan y se vuelve a aplicar
    public function test_migracion_conserva_grados_y_solo_infiere_nombres_inequivocos(): void
    {
        $pasos = DB::table('migrations')->where('migration', '>=', '2026_10_11_000001_add_orden_to_grados_table')->count();
        Artisan::call('migrate:rollback', ['--step' => $pasos]);
        $this->assertFalse(Schema::hasColumn('grados', 'orden'));

        $g = fn ($nombre) => DB::table('grados')->insertGetId(['nombre' => $nombre, 'created_at' => now(), 'updated_at' => now()]);
        $tercero = $g('Tercer grado');
        $primero = $g('1.º grado');
        $ambiguo = $g('1er Año');
        $parvularia = $g('Parvularia 6');
        $dup1 = $g('Segundo grado');      // dos nombres que apuntan al 2: ninguno lo recibe
        $dup2 = $g('2do grado');
        $bachi = $g('Primer año de bachillerato');

        Artisan::call('migrate');

        $orden = DB::table('grados')->pluck('orden', 'id');
        $this->assertSame(7, $orden->count());
        $this->assertSame(3, (int) $orden[$tercero]);
        $this->assertSame(1, (int) $orden[$primero]);
        $this->assertSame(10, (int) $orden[$bachi]);
        foreach ([$ambiguo, $parvularia, $dup1, $dup2] as $id) {
            $this->assertNull($orden[$id]);
        }
        // Los nombres no cambian
        $this->assertSame('1er Año', DB::table('grados')->where('id', $ambiguo)->value('nombre'));

        Artisan::call('siga:grados-sin-orden');
        $salida = Artisan::output();
        foreach (['1er Año', 'Parvularia 6', 'Segundo grado', '2do grado'] as $nombre) {
            $this->assertStringContainsString($nombre, $salida);
        }
        $this->assertStringNotContainsString('Tercer grado', $salida);
        $this->assertStringContainsString('4 grado(s) requieren', $salida);
    }

    private function matriculaAprobadaEn($estudiante, array $catalogos, Grado $grado): void
    {
        $usuarioProfe = $this->usuario(User::ROL_DOCENTE);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe '.$usuarioProfe->id, 'dui' => sprintf('%08d-0', $usuarioProfe->id), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => 'P'.$usuarioProfe->id, 'telefono' => (string) (70000000 + $usuarioProfe->id), 'direccion' => 'X', 'user_id' => $usuarioProfe->id,
        ]);
        $seccion = DB::table('secciones')->insertGetId([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'anio' => now()->year - 1,
            'grado_id' => $grado->id, 'profesor_id' => $profesor,
        ]);
        $matricula = $this->matriculaDe($estudiante, $catalogos, [
            'anio' => (string) (now()->year - 1), 'estado' => 'aprobado', 'seccion_id' => $seccion,
        ]);
        DB::table('notas_anuales')->insert([
            'matricula_id' => $matricula, 'promedio_anual' => 8.5, 'fecha_cierre' => (now()->year - 1).'-11-30', 'estado' => 'Aprobada',
        ]);
    }
}
