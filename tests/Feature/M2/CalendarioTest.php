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
 * las columnas del diagrama ER: el año (anio) y periodos con nombre_periodo y
 * nivel, que el director agrega uno a uno (19/10/2026).
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
            'anio' => 2027, 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30',
        ]);
    }

    private function agregarPeriodo(int $anioId, array $datos)
    {
        return $this->como($this->director)->postJson("/api/anios-lectivos/{$anioId}/periodos", $datos);
    }

    // Agrega tres trimestres al año y devuelve sus ids en orden (nivel)
    private function periodosDe(int $anioId): array
    {
        return array_map(
            fn ($nivel) => $this->agregarPeriodo($anioId, ['nombre_periodo' => "Trimestre {$nivel}", 'nivel' => $nivel])->assertCreated()->json('periodo.id'),
            [1, 2, 3]
        );
    }

    // --- Creación y validación ---

    public function test_crear_anio_sin_periodos(): void
    {
        // Sin "cantidad de periodos": el año nace sin periodos y se agregan con su formulario
        $this->crearAnio(['cantidad_periodos' => 4])->assertCreated()
            ->assertJsonPath('anio_lectivo.anio', 2027)
            ->assertJsonPath('anio_lectivo.estado', 'inactivo')
            ->assertJsonPath('anio_lectivo.fecha_inicio', '2027-01-15')
            ->assertJsonCount(0, 'anio_lectivo.periodos')
            ->assertJsonMissingPath('anio_lectivo.nombre');
        $this->assertSame(0, PeriodoEscolar::count());
    }

    public function test_agregar_periodo_con_su_formulario(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');

        $this->agregarPeriodo($id, ['nombre_periodo' => 'Primer trimestre', 'nivel' => 1])->assertCreated()
            ->assertJsonPath('periodo.nombre_periodo', 'Primer trimestre')->assertJsonPath('periodo.nivel', 1)
            ->assertJsonPath('periodo.anio_lectivo_id', $id)->assertJsonMissingPath('periodo.nombre');

        // Campos obligatorios y nivel de 1 a 6
        $this->agregarPeriodo($id, [])->assertStatus(422)->assertJsonValidationErrors(['nombre_periodo', 'nivel']);
        $this->agregarPeriodo($id, ['nombre_periodo' => 'X', 'nivel' => 0])->assertStatus(422)->assertJsonValidationErrors('nivel');
        $this->agregarPeriodo($id, ['nombre_periodo' => 'X', 'nivel' => 7])->assertStatus(422)->assertJsonValidationErrors('nivel');
        $this->agregarPeriodo($id, ['nombre_periodo' => str_repeat('a', 51), 'nivel' => 2])->assertStatus(422)->assertJsonValidationErrors('nombre_periodo');

        // Sin repetir nivel ni nombre (mayúsculas, tildes y espacios no cuentan) dentro del año
        $this->agregarPeriodo($id, ['nombre_periodo' => 'Otro', 'nivel' => 1])->assertStatus(422)
            ->assertJsonPath('errors.nivel.0', 'El año lectivo 2027 ya tiene un periodo con el nivel 1.');
        $this->agregarPeriodo($id, ['nombre_periodo' => '  primer   TRIMESTRE ', 'nivel' => 2])->assertStatus(422)
            ->assertJsonValidationErrors('nombre_periodo');

        // Otro año sí admite el mismo nombre y nivel
        $otro = $this->crearAnio(['anio' => 2028, 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $this->agregarPeriodo($otro, ['nombre_periodo' => 'Primer trimestre', 'nivel' => 1])->assertCreated();

        // Hasta seis periodos por año
        foreach ([2, 3, 4, 5, 6] as $nivel) {
            $this->agregarPeriodo($id, ['nombre_periodo' => "Periodo {$nivel}", 'nivel' => $nivel])->assertCreated();
        }
        $this->agregarPeriodo($id, ['nombre_periodo' => 'Extra', 'nivel' => 6])->assertStatus(409)
            ->assertJsonPath('message', 'Un año lectivo admite como máximo 6 periodos.');
    }

    public function test_validacion_del_anio(): void
    {
        $this->como($this->director)->postJson('/api/anios-lectivos', [])
            ->assertStatus(422)->assertJsonValidationErrors(['anio', 'fecha_inicio', 'fecha_fin']);
        $this->crearAnio(['anio' => 'dos mil'])->assertStatus(422)->assertJsonValidationErrors('anio');
        $this->crearAnio(['anio' => 1999])->assertStatus(422)->assertJsonValidationErrors('anio');
        $this->crearAnio(['fecha_inicio' => '2027-12-01', 'fecha_fin' => '2027-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('fecha_fin');
        $this->crearAnio(['fecha_inicio' => 'no-es-fecha'])->assertStatus(422)->assertJsonValidationErrors('fecha_inicio');
        $this->crearAnio()->assertCreated();
        $this->crearAnio()->assertStatus(422)->assertJsonPath('errors.anio.0', 'Ya existe un año lectivo con ese año.');
        $this->assertSame(1, AnioLectivo::count());
    }

    // --- Unicidad del año activo ---

    public function test_activar_exige_fechas_y_un_solo_anio_activo(): void
    {
        $a = $this->crearAnio()->json('anio_lectivo.id');
        $b = $this->crearAnio(['anio' => 2028, 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $sinFechas = AnioLectivo::create(['anio' => 2026]);

        $this->como($this->director)->postJson("/api/anios-lectivos/{$sinFechas->id}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Para activar el año lectivo debe tener fecha de inicio y fecha de fin, y la de inicio debe ser anterior.');

        // Sin periodos tampoco se activa
        $this->como($this->director)->postJson("/api/anios-lectivos/{$a}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Agregue al menos un periodo escolar antes de activar el año lectivo.');
        $this->periodosDe($a);
        $this->periodosDe($b);

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
        $this->periodosDe($id);
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409); // inactivo
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk()->assertJsonPath('anio_lectivo.estado', 'cierre');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Un año lectivo cerrado no puede volver a activarse.');
        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['anio' => 2027, 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-12-15'])
            ->assertStatus(409);

        // Cerrado el anterior, se puede activar otro
        $otro = $this->crearAnio(['anio' => 2028, 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $this->periodosDe($otro);
        $this->como($this->director)->postJson("/api/anios-lectivos/{$otro}/activar")->assertOk();
    }

    public function test_editar_anio(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');

        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['anio' => 2030, 'fecha_inicio' => '2027-01-10', 'fecha_fin' => '2027-12-10'])
            ->assertOk()->assertJsonPath('anio_lectivo.anio', 2030)->assertJsonPath('anio_lectivo.fecha_fin', '2027-12-10');
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
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre_periodo' => 'Primer periodo'])
            ->assertOk()->assertJsonPath('periodo.nombre_periodo', 'Primer periodo');
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre_periodo' => 'trimestre 2'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre_periodo');
        $this->como($this->director)->getJson('/api/anios-lectivos/actual')->assertOk()
            ->assertJsonPath('anio_lectivo.id', $id)->assertJsonCount(3, 'anio_lectivo.periodos')
            ->assertJsonMissingPath('periodo_abierto');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk();
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre_periodo' => 'X'])->assertStatus(409);
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
        [$p1, $p2, $p3] = $this->periodosDe($id);

        // Un periodo sin registros se elimina
        $this->como($this->director)->deleteJson("/api/periodos-escolares/{$p2}")->assertOk();
        $this->agregarPeriodo($id, ['nombre_periodo' => 'Trimestre 2', 'nivel' => 2])->assertCreated();

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
        $otro = $this->crearAnio(['anio' => 2028, 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $this->periodosDe($otro);
        DB::table('matriculas')->where('id', $matricula)->update(['anio_lectivo_id' => $otro]);
        $this->como($this->director)->deleteJson("/api/anios-lectivos/{$otro}")
            ->assertStatus(409)->assertJsonPath('message', 'No se puede eliminar el año lectivo porque tiene secciones o matrículas asociadas.');

        // Un año inactivo sin datos sí se elimina, con sus periodos
        $libre = $this->crearAnio(['anio' => 2029, 'fecha_inicio' => '2029-01-15', 'fecha_fin' => '2029-11-30'])->json('anio_lectivo.id');
        $this->periodosDe($libre);
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
        [$periodo] = $this->periodosDe($id);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/anios-lectivos')->assertOk()->assertJsonCount(1, 'anios_lectivos');
            $this->como($cuenta)->getJson('/api/anios-lectivos/actual')->assertOk()->assertJsonPath('message', 'No hay un año lectivo activo.');
            $this->como($cuenta)->getJson("/api/anios-lectivos/{$id}")->assertOk();
            $this->como($cuenta)->getJson("/api/anios-lectivos/{$id}/periodos")->assertOk()->assertJsonCount(3, 'periodos');
            $this->como($cuenta)->getJson("/api/periodos-escolares/{$periodo}")->assertOk();

            $this->como($cuenta)->postJson('/api/anios-lectivos', ['anio' => 2030])->assertStatus(403);
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
        $this->como($this->director)->putJson('/api/periodos-escolares/999', ['nombre_periodo' => 'X'])->assertNotFound()->assertExactJson(['message' => 'Periodo no encontrado']);

        \Illuminate\Support\Facades\Schema::rename('anios_lectivos', 'anios_tmp');
        $this->como($this->director)->getJson('/api/anios-lectivos')->assertStatus(500)->assertExactJson(['message' => 'Error al obtener los años lectivos']);
        \Illuminate\Support\Facades\Schema::rename('anios_tmp', 'anios_lectivos');
    }
}
