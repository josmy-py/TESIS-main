<?php

namespace Tests\Feature\M2;

use App\Models\AnioLectivo;
use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\CalendarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.2: años lectivos y periodos escolares (reglas de estado y permisos), con
 * las columnas del diagrama ER.
 */
class CalendarioTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    private function crearAnio(array $datos = [])
    {
        return $this->como($this->director)->postJson('/api/anios-lectivos', $datos + [
            'nombre' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30',
        ]);
    }

    // Renombra los periodos del año y devuelve sus ids en orden (nivel)
    private function periodosDe(int $anioId): array
    {
        $ids = PeriodoEscolar::where('anio_lectivo_id', $anioId)->orderBy('nivel')->pluck('id')->all();

        foreach ($ids as $i => $id) {
            $this->como($this->director)->putJson("/api/periodos-escolares/{$id}", [
                'nombre' => 'Trimestre '.($i + 1),
            ])->assertOk();
        }

        return $ids;
    }

    // --- Creación y validación ---

    public function test_crear_anio_crea_tres_periodos(): void
    {
        $anio = $this->crearAnio()->assertCreated()
            ->assertJsonPath('anio_lectivo.estado', 'inactivo')
            ->assertJsonPath('anio_lectivo.fecha_inicio', '2027-01-15')
            ->assertJsonCount(3, 'anio_lectivo.periodos')
            ->json('anio_lectivo');

        $this->assertSame([1, 2, 3], array_column($anio['periodos'], 'nivel'));
        $this->assertArrayNotHasKey('estado', $anio['periodos'][0]);
        $this->assertSame(['Primer trimestre', 'Segundo trimestre', 'Tercer trimestre'], array_column($anio['periodos'], 'nombre'));
    }

    public function test_cantidad_de_periodos_configurable(): void
    {
        $this->crearAnio(['cantidad_periodos' => 4])->assertCreated()->assertJsonCount(4, 'anio_lectivo.periodos')
            ->assertJsonPath('anio_lectivo.periodos.3.nombre', 'Cuarto periodo');
        $this->crearAnio(['nombre' => '2028', 'cantidad_periodos' => 0])->assertStatus(422)->assertJsonValidationErrors('cantidad_periodos');
        $this->crearAnio(['nombre' => '2028', 'cantidad_periodos' => 7])->assertStatus(422)->assertJsonValidationErrors('cantidad_periodos');

        // Se puede agregar un periodo después, hasta el máximo
        $id = AnioLectivo::where('nombre', '2027')->value('id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/periodos", ['nombre' => 'Recuperación'])
            ->assertCreated()->assertJsonPath('periodo.nivel', 5)->assertJsonPath('periodo.nombre', 'Recuperación');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/periodos")->assertCreated()
            ->assertJsonPath('periodo.nivel', 6)->assertJsonPath('periodo.nombre', 'Sexto periodo');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/periodos")->assertStatus(409);
    }

    public function test_validacion_del_anio(): void
    {
        $this->como($this->director)->postJson('/api/anios-lectivos', [])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'fecha_inicio', 'fecha_fin']);
        $this->crearAnio(['fecha_inicio' => '2027-12-01', 'fecha_fin' => '2027-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_fin');
        $this->crearAnio(['fecha_inicio' => 'no-es-fecha'])->assertStatus(422)->assertJsonValidationErrors('fecha_inicio');
        $this->crearAnio()->assertCreated();
        $this->crearAnio()->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un año lectivo con ese nombre.');
        $this->assertSame(1, AnioLectivo::count());
    }

    // --- Unicidad del año activo ---

    public function test_activar_exige_fechas_y_un_solo_anio_activo(): void
    {
        $a = $this->crearAnio()->json('anio_lectivo.id');
        $b = $this->crearAnio(['nombre' => '2028', 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $sinFechas = AnioLectivo::create(['nombre' => '2026']);

        $this->como($this->director)->postJson("/api/anios-lectivos/{$sinFechas->id}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Para activar el año lectivo debe tener fecha de inicio y fecha de fin, y la de inicio debe ser anterior.');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$a}/activar")->assertOk()->assertJsonPath('anio_lectivo.estado', 'activo');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$a}/activar")->assertStatus(409);
        $this->como($this->director)->postJson("/api/anios-lectivos/{$b}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Ya existe un año lectivo activo (2027). Ciérrelo antes de activar otro.');
        $this->assertSame(1, AnioLectivo::where('estado', 'activo')->count());

        // Los estados son los del diagrama: la base no acepta otros valores
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('anios_lectivos')->where('id', $b)->update(['estado' => 'planificado']);
    }

    public function test_anio_cerrado_no_se_reactiva_ni_se_edita(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409); // inactivo
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk()->assertJsonPath('anio_lectivo.estado', 'cierre');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Un año lectivo cerrado no puede volver a activarse.');
        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['nombre' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-12-15'])
            ->assertStatus(409);

        // Cerrado el anterior, se puede activar otro
        $otro = $this->crearAnio(['nombre' => '2028', 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$otro}/activar")->assertOk();
    }

    public function test_editar_anio(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');

        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['nombre' => 'Año 2027', 'fecha_inicio' => '2027-01-10', 'fecha_fin' => '2027-12-10'])
            ->assertOk()->assertJsonPath('anio_lectivo.nombre', 'Año 2027')->assertJsonPath('anio_lectivo.fecha_fin', '2027-12-10');
    }

    // --- Periodos ---

    public function test_periodos_se_editan_mientras_el_anio_no_este_cerrado(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1] = $this->periodosDe($id);

        // Abrir, cerrar y reabrir periodos no están en el diagrama
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertNotFound();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/cerrar")->assertNotFound();

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre' => 'Primer periodo'])
            ->assertOk()->assertJsonPath('periodo.nombre', 'Primer periodo');
        $this->como($this->director)->getJson('/api/anios-lectivos/actual')->assertOk()
            ->assertJsonPath('anio_lectivo.id', $id)->assertJsonCount(3, 'anio_lectivo.periodos')
            ->assertJsonMissingPath('periodo_abierto');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk();
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre' => 'X'])->assertStatus(409);
    }

    public function test_regla_preparada_para_notas_y_conducta(): void
    {
        $servicio = app(CalendarioService::class);
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1] = $this->periodosDe($id);

        // Año inactivo: no se registran notas ni conducta
        $this->assertFalse(PeriodoEscolar::find($p1)->permiteRegistros());
        try {
            $servicio->asegurarPeriodoEditable($p1);
            $this->fail('Debía rechazar un periodo de un año inactivo');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('no está activo', $e->getMessage());
        }

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        $this->assertSame($p1, $servicio->asegurarPeriodoEditable($p1)->id);

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk();
        $this->assertFalse(PeriodoEscolar::find($p1)->permiteRegistros());
    }

    // --- Protección de registros con datos asociados ---

    public function test_no_se_borran_anios_ni_periodos_con_datos(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1, $p2, $p3] = PeriodoEscolar::where('anio_lectivo_id', $id)->orderBy('nivel')->pluck('id')->all();

        // Solo el último periodo, para conservar la numeración
        $this->como($this->director)->deleteJson("/api/periodos-escolares/{$p2}")->assertStatus(409);

        // Con registros académicos (conducta) no se borra
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $matricula = $this->matriculaDe($this->estudianteDe($encargado, $catalogos['distrito']), $catalogos);
        $columnas = \Illuminate\Support\Facades\Schema::getColumnListing('conductas');
        $fila = ['periodo_escolar_id' => $p3, 'matricula_id' => $matricula];
        foreach (array_diff($columnas, ['id', 'periodo_escolar_id', 'matricula_id', 'created_at', 'updated_at']) as $columna) {
            $fila[$columna] = 1;
        }
        DB::table('conductas')->insert($fila);

        $this->como($this->director)->deleteJson("/api/periodos-escolares/{$p3}")
            ->assertStatus(409)->assertJsonPath('message', 'No se puede eliminar el periodo porque tiene registros académicos asociados.');
        $this->como($this->director)->deleteJson("/api/anios-lectivos/{$id}")->assertStatus(409);
        $this->assertSame(3, PeriodoEscolar::where('anio_lectivo_id', $id)->count());

        // Con matrículas tampoco
        $otro = $this->crearAnio(['nombre' => '2028', 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        DB::table('matriculas')->where('id', $matricula)->update(['anio_lectivo_id' => $otro]);
        $this->como($this->director)->deleteJson("/api/anios-lectivos/{$otro}")
            ->assertStatus(409)->assertJsonPath('message', 'No se puede eliminar el año lectivo porque tiene secciones o matrículas asociadas.');

        // Un año inactivo sin datos sí se elimina, con sus periodos
        $libre = $this->crearAnio(['nombre' => '2029', 'fecha_inicio' => '2029-01-15', 'fecha_fin' => '2029-11-30'])->json('anio_lectivo.id');
        $this->como($this->director)->deleteJson("/api/anios-lectivos/{$libre}")->assertOk();
        $this->assertSame(0, PeriodoEscolar::where('anio_lectivo_id', $libre)->count());

        // Un año activo no se elimina
        $this->como($this->director)->postJson("/api/anios-lectivos/{$otro}/activar")->assertOk();
        $this->como($this->director)->deleteJson("/api/anios-lectivos/{$otro}")->assertStatus(409);
    }

    // --- Permisos ---

    public function test_permisos_por_rol(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $periodo = PeriodoEscolar::where('anio_lectivo_id', $id)->value('id');

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/anios-lectivos')->assertOk()->assertJsonCount(1, 'anios_lectivos');
            $this->como($cuenta)->getJson('/api/anios-lectivos/actual')->assertOk()->assertJsonPath('message', 'No hay un año lectivo activo.');
            $this->como($cuenta)->getJson("/api/anios-lectivos/{$id}")->assertOk();
            $this->como($cuenta)->getJson("/api/anios-lectivos/{$id}/periodos")->assertOk()->assertJsonCount(3, 'periodos');
            $this->como($cuenta)->getJson("/api/periodos-escolares/{$periodo}")->assertOk();

            $this->como($cuenta)->postJson('/api/anios-lectivos', ['nombre' => 'X'])->assertStatus(403);
            $this->como($cuenta)->putJson("/api/anios-lectivos/{$id}", [])->assertStatus(403);
            $this->como($cuenta)->postJson("/api/anios-lectivos/{$id}/activar")->assertStatus(403);
            $this->como($cuenta)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/anios-lectivos/{$id}")->assertStatus(403);
            $this->como($cuenta)->postJson("/api/anios-lectivos/{$id}/periodos")->assertStatus(403);
            $this->como($cuenta)->putJson("/api/periodos-escolares/{$periodo}", [])->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/periodos-escolares/{$periodo}")->assertStatus(403);
        }

        $this->sinSesion()->getJson('/api/anios-lectivos')->assertStatus(401);
        $this->sinSesion()->getJson('/api/anios-lectivos/actual')->assertStatus(401);
        $this->assertSame('inactivo', AnioLectivo::find($id)->estado);
    }

    public function test_errores_no_exponen_detalles_internos(): void
    {
        $this->como($this->director)->getJson('/api/anios-lectivos/999')->assertNotFound()->assertExactJson(['message' => 'Año lectivo no encontrado']);
        $this->como($this->director)->putJson('/api/periodos-escolares/999', ['nombre' => 'X'])->assertNotFound()->assertExactJson(['message' => 'Periodo no encontrado']);

        \Illuminate\Support\Facades\Schema::rename('anios_lectivos', 'anios_tmp');
        $this->como($this->director)->getJson('/api/anios-lectivos')->assertStatus(500)->assertExactJson(['message' => 'Error al obtener los años lectivos']);
        \Illuminate\Support\Facades\Schema::rename('anios_tmp', 'anios_lectivos');
    }
}
