<?php

namespace Tests\Feature\M2;

use App\Models\Grado;
use App\Models\User;
use App\Support\CatalogoGrados;
use App\Support\OrdenGrado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * Orden académico de los grados (catálogo cerrado de nombres oficiales) y promoción basada en ese orden.
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

    // El orden académico no se guarda (no está en el diagrama): es el del catálogo oficial
    public function test_solo_se_aceptan_grados_del_catalogo_oficial(): void
    {
        $id = $this->como($this->director)->postJson('/api/grados', ['nombre' => 'Primer grado', 'orden' => 7])
            ->assertCreated()->assertJsonPath('grado.orden', 1)->json('grado.id');
        $this->assertFalse(Schema::hasColumn('grados', 'orden'));

        // Mayúsculas, tildes y espacios no importan: se guarda el nombre oficial
        $this->como($this->director)->postJson('/api/grados', ['nombre' => '  parvularia 4 AÑOS '])
            ->assertCreated()->assertJsonPath('grado.nombre', 'Parvularia 4 años')->assertJsonPath('grado.orden', -2);
        $this->como($this->director)->postJson('/api/grados', ['nombre' => 'septimo grado'])
            ->assertCreated()->assertJsonPath('grado.nombre', 'Séptimo grado');

        // Cualquier otro nombre se rechaza, igual que un grado repetido
        foreach (['1er Año', 'Parvularia 4', 'Kinder', 'Primer grado A', 'Tercer año de bachillerato', ''] as $nombre) {
            $this->como($this->director)->postJson('/api/grados', ['nombre' => $nombre])
                ->assertStatus(422)->assertJsonValidationErrors('nombre');
        }
        $this->como($this->director)->postJson('/api/grados', ['nombre' => 'PRIMER GRADO'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'El grado ya está registrado.');

        // Sin uso, el nombre se puede corregir dentro del catálogo
        $this->como($this->director)->putJson("/api/grados/{$id}", ['nombre' => 'Segundo grado'])
            ->assertOk()->assertJsonPath('grado.orden', 2);
        $this->como($this->director)->putJson("/api/grados/{$id}", ['nombre' => 'Grado 2'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertSame('Segundo grado', Grado::find($id)->nombre);
    }

    public function test_un_grado_en_uso_no_cambia_de_nombre(): void
    {
        $grado = Grado::create(['nombre' => 'Primer grado']);
        DB::table('plan_estudios')->insert([
            'nombre' => 'Plan', 'estado' => true, 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2030-12-31',
            'descripcion' => 'X', 'fecha_aprobacion' => '2025-12-01', 'grado_id' => $grado->id,
        ]);

        $this->como($this->director)->putJson("/api/grados/{$grado->id}", ['nombre' => 'Segundo grado'])->assertStatus(409);
        // Guardar el mismo nombre sí se permite
        $this->como($this->director)->putJson("/api/grados/{$grado->id}", ['nombre' => 'primer grado'])->assertOk();
        $this->assertSame('Primer grado', $grado->fresh()->nombre);
    }

    public function test_catalogo_lista_los_grados_oficiales_y_los_registrados(): void
    {
        $grado = Grado::create(['nombre' => 'Tercer grado']);
        $encargado = $this->usuario(User::ROL_ENCARGADO);

        $catalogo = $this->como($encargado)->getJson('/api/grados/catalogo')->assertOk()->json('catalogo');
        $this->assertSame(array_keys(CatalogoGrados::GRADOS), array_column($catalogo, 'nombre'));
        $this->assertSame([-2, -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], array_column($catalogo, 'orden'));
        $this->assertSame($grado->id, collect($catalogo)->firstWhere('nombre', 'Tercer grado')['grado_id']);
        $this->assertNull(collect($catalogo)->firstWhere('nombre', 'Cuarto grado')['grado_id']);
        $this->sinSesion()->getJson('/api/grados/catalogo')->assertStatus(401);
    }

    public function test_catalogo_reconoce_equivalentes_inequivocos_de_nombres_anteriores(): void
    {
        $casos = [
            'Primer grado' => 'Primer grado', '1er grado' => 'Primer grado', '1° Grado' => 'Primer grado',
            'Setimo grado' => 'Séptimo grado', '2.º año bachillerato' => 'Segundo año de bachillerato',
            'Parvularia 4' => 'Parvularia 4 años', 'parvularia 6 años' => 'Parvularia 6 años',
            '1er Año' => null, 'Kinder' => null, 'Parvularia' => null, 'Parvularia 3' => null, 'Décimo grado' => null,
            'Tercer año de bachillerato' => null, // Bachillerato General: dos años
        ];

        foreach ($casos as $nombre => $esperado) {
            $this->assertSame($esperado, CatalogoGrados::equivalente($nombre), "Nombre: '{$nombre}'");
        }
        // Solo el nombre oficial tiene orden
        $this->assertNull(CatalogoGrados::orden('1er grado'));
        $this->assertSame(1, CatalogoGrados::orden('primer GRADO'));
    }

    public function test_solo_el_director_escribe_y_los_demas_consultan(): void
    {
        $grado = Grado::create(['nombre' => 'Primer grado']);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/grados')->assertOk();
            $this->como($cuenta)->postJson('/api/grados', ['nombre' => 'X'])->assertStatus(403);
            $this->como($cuenta)->putJson("/api/grados/{$grado->id}", ['nombre' => 'X'])->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/grados/{$grado->id}")->assertStatus(403);
        }
        $this->sinSesion()->getJson('/api/grados')->assertStatus(401);
    }

    public function test_listado_en_orden_academico_con_pendientes_al_final(): void
    {
        // Ids en un orden distinto al académico
        Grado::create(['nombre' => 'Noveno grado']);
        Grado::create(['nombre' => 'Parvularia 5 años']);
        Grado::create(['nombre' => 'Primer grado']);
        Grado::create(['nombre' => 'Primer año de bachillerato']);
        DB::table('grados')->insert(['nombre' => 'Grado heredado']); // dato anterior fuera del catálogo

        $this->como($this->director)->getJson('/api/grados')->assertOk()
            ->assertJsonPath('grados.*.nombre', ['Parvularia 5 años', 'Primer grado', 'Noveno grado', 'Primer año de bachillerato', 'Grado heredado']);
    }

    public function test_promocion_sigue_el_orden_y_no_el_id(): void
    {
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $catalogos = $this->catalogosBasicos();

        // Se crean en desorden: el id de "Segundo" no es el de "Primero" + 1
        $segundo = Grado::create(['nombre' => 'Segundo grado']);
        $tercero = Grado::create(['nombre' => 'Tercer grado']);
        $primero = Grado::create(['nombre' => 'Primer grado']);

        $estudiante = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($estudiante, $catalogos, $primero);

        $validar = fn ($grado) => $this->como($encargado)
            ->postJson("/api/matriculas/validar-grado/{$estudiante->id}/{$grado->id}", ['ingreso' => 'Reingreso']);

        $validar($segundo)->assertOk()->assertJsonPath('permitido', true);
        $validar($tercero)->assertStatus(422)->assertJsonPath('permitido', false)->assertJsonPath('grado_permitido', $segundo->id);
        // Con la regla anterior (id + 1) el grado permitido no habría existido
        $this->assertNull(Grado::find($primero->id + 1));

        // M6.1 (D-M5): nunca se salta un grado; si el siguiente no está registrado se bloquea
        $segundo->delete();
        $validar($tercero)->assertStatus(422)->assertJsonPath('permitido', false)
            ->assertJsonPath('message', 'El grado siguiente (Segundo grado) no está registrado en SIGA. No se puede saltar grados: regístrelo antes de matricular.');

        // De Parvularia 6 años se pasa a Primer grado
        $parvularia = Grado::create(['nombre' => 'Parvularia 6 años']);
        $otro = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($otro, $catalogos, $parvularia);
        $this->como($encargado)->postJson("/api/matriculas/validar-grado/{$otro->id}/{$primero->id}", ['ingreso' => 'Reingreso'])
            ->assertOk()->assertJsonPath('permitido', true);
    }

    public function test_promocion_falla_controlada_sin_orden_o_sin_siguiente(): void
    {
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $catalogos = $this->catalogosBasicos();

        $sinOrden = Grado::create(['nombre' => 'Grado heredado']); // dato anterior al catálogo
        $ultimo = Grado::create(['nombre' => 'Noveno grado']);
        $otro = Grado::create(['nombre' => 'Octavo grado']);

        $a = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($a, $catalogos, $sinOrden);
        $this->como($encargado)->postJson("/api/matriculas/validar-grado/{$a->id}/{$ultimo->id}", ['ingreso' => 'Reingreso'])
            ->assertStatus(422)->assertJsonPath('permitido', false)
            ->assertJsonPath('message', 'No se puede determinar el grado de la matrícula aprobada de (año sin registrar): no tiene sección o su grado no está en el catálogo oficial.');

        $b = $this->estudianteDe($encargado, $catalogos['distrito']);
        $this->matriculaAprobadaEn($b, $catalogos, $ultimo);
        $this->como($encargado)->postJson("/api/matriculas/validar-grado/{$b->id}/{$otro->id}", ['ingreso' => 'Reingreso'])
            ->assertStatus(422)->assertJsonPath('permitido', false)->assertJsonPath('grado_permitido', null)
            ->assertJsonPath('message', 'El grado siguiente (Primer año de bachillerato) no está registrado en SIGA. No se puede saltar grados: regístrelo antes de matricular.');
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

    public function test_comando_lista_grados_fuera_del_catalogo(): void
    {
        foreach (['Tercer grado', '1er Año', 'Parvularia 6'] as $nombre) {
            Grado::create(['nombre' => $nombre]);
        }

        Artisan::call('siga:grados-sin-orden');
        $salida = Artisan::output();
        $this->assertStringContainsString('1er Año', $salida);
        $this->assertStringContainsString('Parvularia 6 años', $salida); // equivalente sugerido
        $this->assertStringNotContainsString('Tercer grado', $salida);
        $this->assertStringContainsString('2 grado(s)', $salida);
    }

    private function matriculaAprobadaEn($estudiante, array $catalogos, Grado $grado): void
    {
        $usuarioProfe = $this->usuario(User::ROL_DOCENTE);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe '.$usuarioProfe->id, 'dui' => sprintf('%08d-0', $usuarioProfe->id), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => 'P'.$usuarioProfe->id, 'telefono' => (string) (70000000 + $usuarioProfe->id), 'direccion' => 'X', 'user_id' => $usuarioProfe->id,
        ]);
        $seccion = DB::table('secciones')->insertGetId([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino',
            'grado_id' => $grado->id, 'profesor_id' => $profesor,
        ]);
        $matricula = $this->matriculaDe($estudiante, $catalogos, [
            'estado' => 'aprobado', 'seccion_id' => $seccion,
        ]);
        DB::table('notas_anuales')->insert([
            'matricula_id' => $matricula, 'promedio_anual' => 8.5, 'estado' => 'Aprobada',
        ]);
    }
}
