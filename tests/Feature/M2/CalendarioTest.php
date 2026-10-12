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
 * M2.2: años lectivos y periodos escolares (reglas de estado, auditoría y permisos).
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

    private function fechasPeriodos(int $anioId): array
    {
        $fechas = [['2027-01-15', '2027-04-30'], ['2027-05-03', '2027-08-13'], ['2027-08-16', '2027-11-30']];
        $ids = PeriodoEscolar::where('anio_lectivo_id', $anioId)->orderBy('numero')->pluck('id')->all();

        foreach ($ids as $i => $id) {
            $this->como($this->director)->putJson("/api/periodos-escolares/{$id}", [
                'nombre' => 'Trimestre '.($i + 1), 'fecha_inicio' => $fechas[$i][0], 'fecha_fin' => $fechas[$i][1],
            ])->assertOk();
        }

        return $ids;
    }

    /** Abre y cierra cada periodo del año (requiere el año activo) */
    private function completarPeriodos(int $anioId): void
    {
        foreach ($this->fechasPeriodos($anioId) as $id) {
            $this->como($this->director)->postJson("/api/periodos-escolares/{$id}/abrir")->assertOk();
            $this->como($this->director)->postJson("/api/periodos-escolares/{$id}/cerrar")->assertOk();
        }
    }

    // --- Creación y validación ---

    public function test_crear_anio_crea_tres_periodos_pendientes(): void
    {
        $anio = $this->crearAnio()->assertCreated()
            ->assertJsonPath('anio_lectivo.estado', 'planificado')
            ->assertJsonPath('anio_lectivo.fecha_inicio', '2027-01-15')
            ->assertJsonCount(3, 'anio_lectivo.periodos')
            ->json('anio_lectivo');

        $this->assertSame([1, 2, 3], array_column($anio['periodos'], 'numero'));
        $this->assertSame(['pendiente'], array_values(array_unique(array_column($anio['periodos'], 'estado'))));
        $this->assertSame(['Primer trimestre', 'Segundo trimestre', 'Tercer trimestre'], array_column($anio['periodos'], 'nombre'));
        $this->assertArrayNotHasKey('activo_unico', $anio);
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
            ->assertCreated()->assertJsonPath('periodo.numero', 5)->assertJsonPath('periodo.estado', 'pendiente');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/periodos")->assertCreated()
            ->assertJsonPath('periodo.numero', 6)->assertJsonPath('periodo.nombre', 'Sexto periodo');
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

    public function test_validacion_de_fechas_de_periodos(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1, $p2] = PeriodoEscolar::where('anio_lectivo_id', $id)->orderBy('numero')->pluck('id')->all();
        $editar = fn ($periodo, $inicio, $fin) => $this->como($this->director)
            ->putJson("/api/periodos-escolares/{$periodo}", ['nombre' => 'P', 'fecha_inicio' => $inicio, 'fecha_fin' => $fin]);

        $editar($p1, '2027-01-01', '2027-04-30')->assertStatus(422)->assertJsonValidationErrors('fecha_inicio'); // antes del año
        $editar($p1, '2027-04-30', '2027-01-15')->assertStatus(422)->assertJsonValidationErrors('fecha_fin');    // invertidas
        $editar($p1, '2027-01-15', null)->assertStatus(422);                                                   // incompleta
        $editar($p1, '2027-01-15', '2027-04-30')->assertOk();
        $editar($p2, '2027-04-15', '2027-08-13')->assertStatus(422)->assertJsonValidationErrors('fecha_inicio'); // superpuesto
        $editar($p2, '2027-05-03', '2027-08-13')->assertOk();
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

        // La base también lo impide aunque se salte el servicio
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('anios_lectivos')->where('id', $b)->update(['estado' => 'activo', 'activo_unico' => true]);
    }

    public function test_anio_cerrado_no_se_reactiva_ni_se_edita(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409); // planificado
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        $this->completarPeriodos($id);
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk()->assertJsonPath('anio_lectivo.estado', 'cerrado');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")
            ->assertStatus(409)->assertJsonPath('message', 'Un año lectivo cerrado no puede volver a activarse.');
        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['nombre' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-12-15'])
            ->assertStatus(409);

        // Cerrado el anterior, se puede activar otro
        $otro = $this->crearAnio(['nombre' => '2028', 'fecha_inicio' => '2028-01-15', 'fecha_fin' => '2028-11-30'])->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$otro}/activar")->assertOk();
    }

    public function test_anio_solo_se_cierra_con_todos_los_periodos_cerrados(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        [$p1, $p2, $p3] = $this->fechasPeriodos($id);

        // Todos pendientes
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409)
            ->assertJsonPath('message', 'Cierre todos los periodos antes de cerrar el año lectivo. Periodos sin cerrar: Trimestre 1, Trimestre 2, Trimestre 3.');

        // Uno abierto y dos pendientes
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertOk();
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409);

        // Uno cerrado y otro pendiente: tampoco
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/cerrar")->assertOk();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p2}/abrir")->assertOk();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p2}/cerrar")->assertOk();
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertStatus(409)
            ->assertJsonPath('message', 'Cierre todos los periodos antes de cerrar el año lectivo. Periodos sin cerrar: Trimestre 3.');
        $this->assertSame('activo', AnioLectivo::find($id)->estado);

        // Todos cerrados: se cierra
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p3}/abrir")->assertOk();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p3}/cerrar")->assertOk();
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/cerrar")->assertOk()->assertJsonPath('anio_lectivo.estado', 'cerrado');
    }

    public function test_editar_anio_no_deja_periodos_fuera_de_rango(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->fechasPeriodos($id);

        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['nombre' => '2027', 'fecha_inicio' => '2027-02-01', 'fecha_fin' => '2027-11-30'])
            ->assertStatus(409);
        $this->como($this->director)->putJson("/api/anios-lectivos/{$id}", ['nombre' => 'Año 2027', 'fecha_inicio' => '2027-01-10', 'fecha_fin' => '2027-12-10'])
            ->assertOk()->assertJsonPath('anio_lectivo.nombre', 'Año 2027');
    }

    // --- Periodos: apertura, cierre, reapertura y auditoría ---

    public function test_abrir_periodo_exige_anio_activo_fechas_y_unico_abierto(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1, $p2] = PeriodoEscolar::where('anio_lectivo_id', $id)->orderBy('numero')->pluck('id')->all();

        // Año planificado
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")
            ->assertStatus(409)->assertJsonPath('message', 'El periodo solo puede abrirse cuando su año lectivo está activo.');

        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        // Sin fechas
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")
            ->assertStatus(409)->assertJsonPath('message', 'Para abrir el periodo debe tener fechas válidas dentro del año lectivo.');

        $this->fechasPeriodos($id);
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertOk()->assertJsonPath('periodo.estado', 'abierto');
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p2}/abrir")
            ->assertStatus(409)->assertJsonPath('message', 'Ya hay un periodo abierto en este año (Trimestre 1). Ciérrelo primero.');

        // Un periodo abierto ya no se edita
        $this->como($this->director)->putJson("/api/periodos-escolares/{$p1}", ['nombre' => 'X', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-04-30'])
            ->assertStatus(409);


        $this->como($this->director)->getJson('/api/anios-lectivos/actual')->assertOk()
            ->assertJsonPath('anio_lectivo.id', $id)->assertJsonPath('periodo_abierto.id', $p1);

        // La base impide dos abiertos en el mismo año
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('periodo_escolares')->where('id', $p2)->update(['estado' => 'abierto', 'abierto_en' => $id]);
    }

    public function test_cerrar_y_reabrir_con_motivo_queda_auditado(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        [$p1, $p2] = $this->fechasPeriodos($id);

        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertOk();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/cerrar")->assertOk()->assertJsonPath('periodo.estado', 'cerrado');
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/cerrar")->assertStatus(409);

        // Un cerrado no se "abre": se reabre con motivo
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertStatus(409);
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/reabrir")->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/reabrir", ['motivo' => 'corto'])->assertStatus(422);

        // No se reabre mientras haya otro abierto
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p2}/abrir")->assertOk();
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/reabrir", ['motivo' => 'Corrección de notas del primer trimestre'])->assertStatus(409);
        $this->como($this->director)->postJson("/api/periodos-escolares/{$p2}/cerrar")->assertOk();

        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/reabrir", ['motivo' => 'Corrección de notas del primer trimestre'])
            ->assertOk()->assertJsonPath('periodo.estado', 'abierto');

        $reapertura = DB::table('auditoria_calendario')->where('accion', 'reabrir')->sole();
        $this->assertSame('Corrección de notas del primer trimestre', $reapertura->motivo);
        $this->assertSame($this->director->id, (int) $reapertura->user_id);
        $this->assertSame(['cerrado', 'abierto'], [$reapertura->estado_anterior, $reapertura->estado_nuevo]);

        $historial = $this->como($this->director)->getJson("/api/anios-lectivos/{$id}/historial")->assertOk()->json('historial');
        $this->assertSame(['reabrir', 'cerrar', 'abrir', 'cerrar', 'abrir', 'activar'], array_column($historial, 'accion'));
        $this->assertSame($this->director->email, $historial[0]['usuario']['email']);
    }

    public function test_regla_preparada_para_notas_y_conducta(): void
    {
        $servicio = app(CalendarioService::class);
        $id = $this->crearAnio()->json('anio_lectivo.id');
        $this->como($this->director)->postJson("/api/anios-lectivos/{$id}/activar")->assertOk();
        [$p1] = $this->fechasPeriodos($id);

        $this->assertFalse(PeriodoEscolar::find($p1)->permiteRegistros());
        try {
            $servicio->asegurarPeriodoAbierto($p1);
            $this->fail('Debía rechazar un periodo pendiente');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('no está abierto', $e->getMessage());
        }

        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/abrir")->assertOk();
        $this->assertSame($p1, $servicio->asegurarPeriodoAbierto($p1)->id);

        $this->como($this->director)->postJson("/api/periodos-escolares/{$p1}/cerrar")->assertOk();
        $this->assertFalse(PeriodoEscolar::find($p1)->permiteRegistros());
    }

    // --- Protección de registros con datos asociados ---

    public function test_no_se_borran_anios_ni_periodos_con_datos(): void
    {
        $id = $this->crearAnio()->json('anio_lectivo.id');
        [$p1, $p2, $p3] = PeriodoEscolar::where('anio_lectivo_id', $id)->orderBy('numero')->pluck('id')->all();

        // Solo el último periodo, para conservar la numeración
        $this->como($this->director)->deleteJson("/api/periodos-escolares/{$p2}")->assertStatus(409);

        // Con registros académicos (conducta) no se borra
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
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

        // Un año planificado sin datos sí se elimina, con sus periodos
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
            $this->como($cuenta)->getJson("/api/anios-lectivos/{$id}/historial")->assertStatus(403);
            $this->como($cuenta)->getJson('/api/anios-lectivos/diagnostico')->assertStatus(403);
            $this->como($cuenta)->putJson("/api/periodos-escolares/{$periodo}", [])->assertStatus(403);
            $this->como($cuenta)->postJson("/api/periodos-escolares/{$periodo}/abrir")->assertStatus(403);
            $this->como($cuenta)->postJson("/api/periodos-escolares/{$periodo}/cerrar")->assertStatus(403);
            $this->como($cuenta)->postJson("/api/periodos-escolares/{$periodo}/reabrir", ['motivo' => 'motivo suficiente'])->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/periodos-escolares/{$periodo}")->assertStatus(403);
        }

        $this->sinSesion()->getJson('/api/anios-lectivos')->assertStatus(401);
        $this->sinSesion()->getJson('/api/anios-lectivos/actual')->assertStatus(401);
        $inactivo = $this->usuario(User::ROL_DIRECTOR, [], User::ESTADO_INACTIVO);
        $this->como($inactivo)->postJson("/api/anios-lectivos/{$id}/activar")->assertStatus(403);
        $this->assertSame('planificado', AnioLectivo::find($id)->estado);
        $this->assertSame(0, DB::table('auditoria_calendario')->count());
    }

    public function test_errores_no_exponen_detalles_internos(): void
    {
        $this->como($this->director)->getJson('/api/anios-lectivos/999')->assertNotFound()->assertExactJson(['message' => 'Año lectivo no encontrado']);
        $this->como($this->director)->postJson('/api/periodos-escolares/999/abrir')->assertNotFound()->assertExactJson(['message' => 'Periodo no encontrado']);

        \Illuminate\Support\Facades\Schema::rename('anios_lectivos', 'anios_tmp');
        $this->como($this->director)->getJson('/api/anios-lectivos')->assertStatus(500)->assertExactJson(['message' => 'Error al obtener los años lectivos']);
        \Illuminate\Support\Facades\Schema::rename('anios_tmp', 'anios_lectivos');
    }
}
