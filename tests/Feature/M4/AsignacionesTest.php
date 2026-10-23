<?php

namespace Tests\Feature\M4;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\Grado;
use App\Models\PlanEstudio;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use App\Services\AsignacionService;
use Database\Seeders\AsignacionPruebaSeeder;
use Database\Seeders\ProfesorPruebaSeeder;
use Database\Seeders\SeccionPruebaSeeder;
use Database\Seeders\UsuarioPruebaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\M1\DatosM1;
use Tests\Concerns\HoyFijo;
use Tests\TestCase;

/**
 * M4: asignación académica (decisiones D-A1 a D-A6 del 13/10/2026; tesis, págs. 58, 65 y 68).
 */
class AsignacionesTest extends TestCase
{
    use DatosM1, RefreshDatabase, HoyFijo;

    private User $director;
    private User $encargado;
    private array $catalogos;
    private AnioLectivo $anio;
    private Grado $primero;
    private Grado $bachillerato;
    private Profesor $docente;
    private Profesor $otroDocente;
    private Seccion $seccion;
    private PlanEstudio $plan;
    private Asignatura $matematica;
    private Asignatura $lenguaje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->encargado = $this->usuario(User::ROL_ENCARGADO);
        $this->catalogos = $this->catalogosBasicos();
        $this->anio = $this->anio('2026', AnioLectivo::ACTIVO);
        $this->primero = Grado::create(['nombre' => 'Primer grado']);
        $this->bachillerato = Grado::create(['nombre' => 'Primer año de bachillerato']);
        $this->docente = $this->nuevoDocente();
        $this->otroDocente = $this->nuevoDocente();
        $this->seccion = $this->seccion($this->primero);
        $this->plan = $this->plan($this->primero);
        $this->matematica = Asignatura::create(['nombre' => 'Matemática', 'plan_estudio_id' => $this->plan->id]);
        $this->lenguaje = Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $this->plan->id]);
    }

    // --- Utilidades ---

    private function anio(string $nombre, string $estado = AnioLectivo::INACTIVO): AnioLectivo
    {
        $anio = AnioLectivo::create(['anio' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30",
            'inicio_matricula' => ($nombre - 2).'-01-01', 'fin_matricula' => "{$nombre}-11-30"]);
        $anio->forceFill(['estado' => $estado])->save();

        return $anio;
    }

    private function nuevoDocente(string $rol = User::ROL_DOCENTE): Profesor
    {
        static $n = 0;
        $n++;
        $user = $this->usuario($rol);

        return Profesor::create([
            'nombre' => "Docente {$n}", 'dui' => sprintf('2%07d-0', $n), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => sprintf('PROF-%03d', $n), 'telefono' => sprintf('7100-%04d', $n), 'direccion' => 'X', 'user_id' => $user->id,
        ]);
    }

    private function seccion(Grado $grado, ?AnioLectivo $anio = null, string $nombre = 'A'): Seccion
    {
        return Seccion::create([
            'nombre' => $nombre, 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => ($anio ?? $this->anio)->id,
            'grado_id' => $grado->id, 'profesor_id' => $this->docente->id,
        ]);
    }

    private function plan(Grado $grado, bool $vigente = true, ?int $especialidadId = null, string $nombre = 'Plan'): PlanEstudio
    {
        return PlanEstudio::create([
            'nombre' => $nombre, 'grado_id' => $grado->id, 'especialidad_id' => $especialidadId, 'estado' => $vigente,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
    }

    private function asignar(array $cambios = [])
    {
        return $this->como($this->director)->postJson('/api/asignaciones', $cambios + [
            'profesor_id' => $this->docente->id, 'seccion_id' => $this->seccion->id, 'asignatura_id' => $this->matematica->id,
        ]);
    }

    private function carga(Profesor $profesor, array $pares, ?AnioLectivo $anio = null)
    {
        return $this->como($this->director)->putJson("/api/profesores/{$profesor->id}/carga", [
            'anio_lectivo_id' => ($anio ?? $this->anio)->id,
            'asignaciones' => array_map(fn ($p) => ['seccion_id' => $p[0]->id, 'asignatura_id' => $p[1]->id], $pares),
        ]);
    }

    private function conEvaluacion(int $asignacionId): void
    {
        $periodo = DB::table('periodo_escolares')->insertGetId(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $this->anio->id]);
        DB::table('evaluaciones')->insert(['nombre' => 'Examen', 'porcentaje' => 35, 'periodo_escolar_id' => $periodo, 'asignacion_id' => $asignacionId]);
    }

    private function conBoleta(int $asignacionId): void
    {
        $periodo = DB::table('periodo_escolares')->insertGetId(['nombre' => 'Segundo trimestre', 'nivel' => 2, 'anio_lectivo_id' => $this->anio->id]);
        $matricula = $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, [
            'anio_lectivo_id' => $this->anio->id, 'seccion_id' => $this->seccion->id, 'estado' => 'aprobado',
        ]);
        DB::table('boletas')->insert(['promedio' => 8.5, 'matricula_id' => $matricula, 'asignacion_id' => $asignacionId, 'periodo_escolar_id' => $periodo]);
    }

    // --- Crear y pensum ---

    public function test_director_asigna_materia_del_pensum_vigente(): void
    {
        $this->asignar()->assertCreated()
            ->assertJsonPath('asignacion.profesor.codigo', $this->docente->codigo)
            ->assertJsonPath('asignacion.asignatura.nombre', 'Matemática')
            ->assertJsonPath('asignacion.seccion.grado.nombre', 'Primer grado')
            ->assertJsonPath('asignacion.evaluaciones_count', 0);
    }

    public function test_materia_de_plan_no_vigente_no_se_asigna(): void
    {
        $viejo = $this->plan($this->primero, false, null, 'Plan 2020');
        $arte = Asignatura::create(['nombre' => 'Arte', 'plan_estudio_id' => $viejo->id]);

        $this->asignar(['asignatura_id' => $arte->id])->assertStatus(422)->assertJsonValidationErrors('asignatura_id');
    }

    public function test_d_a1_una_seccion_no_mezcla_planes_de_distintas_especialidades(): void
    {
        $general = $this->plan($this->bachillerato, true, $this->catalogos['especialidad'], 'Bachillerato General');
        $tecnico = $this->plan($this->bachillerato, true, DB::table('especialidades')->insertGetId(['nombre' => 'Técnico']), 'Bachillerato Técnico');
        $letras = Asignatura::create(['nombre' => 'Letras', 'plan_estudio_id' => $general->id]);
        $contabilidad = Asignatura::create(['nombre' => 'Contabilidad', 'plan_estudio_id' => $tecnico->id]);
        $seccion = $this->seccion($this->bachillerato);

        $this->asignar(['seccion_id' => $seccion->id, 'asignatura_id' => $letras->id])->assertCreated();
        $this->asignar(['seccion_id' => $seccion->id, 'asignatura_id' => $contabilidad->id, 'profesor_id' => $this->otroDocente->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'La sección Primer año de bachillerato A ya usa el plan Bachillerato General: no se pueden mezclar materias de otro plan o especialidad.');

        // Otra sección del mismo grado sí puede usar el otro plan
        $seccionB = $this->seccion($this->bachillerato, null, 'B');
        $this->asignar(['seccion_id' => $seccionB->id, 'asignatura_id' => $contabilidad->id])->assertCreated();
        $this->assertSame($tecnico->id, app(AsignacionService::class)->planDeSeccion($seccionB->id)->id);
    }

    public function test_d_a2_una_materia_de_una_seccion_tiene_un_solo_docente(): void
    {
        $this->asignar()->assertCreated();

        $this->asignar(['profesor_id' => $this->otroDocente->id])->assertStatus(409)
            ->assertJsonPath('message', 'La materia Matemática de Primer grado A ya la imparte '.$this->docente->nombre.'.');
        $this->asignar()->assertStatus(409)
            ->assertJsonPath('message', 'La materia Matemática de Primer grado A ya está asignada a este docente.');

        // La misma materia en otra sección sí se asigna
        $this->asignar(['seccion_id' => $this->seccion($this->primero, null, 'B')->id, 'profesor_id' => $this->otroDocente->id])->assertCreated();
        $this->assertSame(2, Asignacion::count());
    }

    public function test_d_a2_indice_unico_en_la_base(): void
    {
        DB::table('asignaciones')->insert(['profesor_id' => $this->docente->id, 'seccion_id' => $this->seccion->id, 'asignatura_id' => $this->matematica->id]);

        $this->expectException(QueryException::class);
        DB::table('asignaciones')->insert(['profesor_id' => $this->otroDocente->id, 'seccion_id' => $this->seccion->id, 'asignatura_id' => $this->matematica->id]);
    }

    public function test_migracion_del_indice_se_detiene_si_hay_duplicados_sin_borrar_datos(): void
    {
        $migracion = require database_path('migrations/2026_10_13_000009_add_unique_seccion_asignatura_to_asignaciones.php');
        $migracion->down();
        foreach ([$this->docente, $this->otroDocente] as $profesor) {
            DB::table('asignaciones')->insert(['profesor_id' => $profesor->id, 'seccion_id' => $this->seccion->id, 'asignatura_id' => $this->matematica->id]);
        }

        $this->assertCount(1, app(AsignacionService::class)->diagnostico()['duplicadas']);
        try {
            $migracion->up();
            $this->fail('La migración debió detenerse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('siga:diagnostico-asignaciones', $e->getMessage());
        }
        $this->assertSame(2, Asignacion::count());

        // Corregido el duplicado, el índice se crea
        Asignacion::where('profesor_id', $this->otroDocente->id)->delete();
        $migracion->up();
        $this->expectException(QueryException::class);
        DB::table('asignaciones')->insert(['profesor_id' => $this->otroDocente->id, 'seccion_id' => $this->seccion->id, 'asignatura_id' => $this->matematica->id]);
    }

    public function test_docente_debe_tener_cuenta_docente(): void
    {
        $sinRolDocente = $this->nuevoDocente(User::ROL_ENCARGADO);
        $this->asignar(['profesor_id' => $sinRolDocente->id])->assertStatus(422)
            ->assertJsonPath('errors.profesor_id.0', 'El docente debe tener una cuenta DOCENTE.');
        $this->asignar(['profesor_id' => 999])->assertStatus(422)->assertJsonValidationErrors('profesor_id');
    }

    // --- Años lectivos (D-A4) ---

    public function test_d_a4_anio_inactivo_admite_asignaciones_y_el_cerrado_no(): void
    {
        $siguiente = $this->anio('2027');
        $seccion2027 = $this->seccion($this->primero, $siguiente);
        $this->asignar(['seccion_id' => $seccion2027->id])->assertCreated();

        $cerrado = $this->anio('2025', AnioLectivo::CIERRE);
        $seccion2025 = $this->seccion($this->primero, $cerrado);
        $this->asignar(['seccion_id' => $seccion2025->id])->assertStatus(422)->assertJsonValidationErrors('seccion_id');
        $this->carga($this->docente, [[$seccion2025, $this->lenguaje]], $cerrado)->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
    }

    public function test_en_anio_cerrado_no_se_cambia_docente_ni_se_elimina(): void
    {
        $id = $this->asignar()->json('asignacion.id');
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();

        $this->como($this->director)->putJson("/api/asignaciones/{$id}", ['profesor_id' => $this->otroDocente->id])
            ->assertStatus(422)->assertJsonValidationErrors('profesor_id');
        $this->como($this->director)->deleteJson("/api/asignaciones/{$id}")->assertStatus(409);
        $this->assertSame($this->docente->id, Asignacion::find($id)->profesor_id);
    }

    // --- Cambiar docente y eliminar (D-A3) ---

    public function test_d_a3_cambiar_docente_conserva_evaluaciones_y_queda_registrado(): void
    {
        $id = $this->asignar()->json('asignacion.id');
        $this->conEvaluacion($id);

        $this->como($this->director)->putJson("/api/asignaciones/{$id}", ['profesor_id' => $this->otroDocente->id])
            ->assertOk()
            ->assertJsonPath('asignacion.profesor.id', $this->otroDocente->id)
            ->assertJsonPath('asignacion.evaluaciones_count', 1);

        $this->assertSame(1, DB::table('evaluaciones')->where('asignacion_id', $id)->count());

        // El mismo docente no es un cambio
        $this->como($this->director)->putJson("/api/asignaciones/{$id}", ['profesor_id' => $this->otroDocente->id])
            ->assertStatus(422)->assertJsonPath('errors.profesor_id.0', 'El docente seleccionado ya imparte esta materia.');
    }

    public function test_bitacora_registra_quien_y_que_cambio(): void
    {
        $id = $this->asignar()->json('asignacion.id');
        $registros = [];
        Log::shouldReceive('channel')->with('asignaciones')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function ($accion, $datos) use (&$registros) {
            $registros[] = [$accion, $datos];
        });

        $this->como($this->director)->putJson("/api/asignaciones/{$id}", ['profesor_id' => $this->otroDocente->id])->assertOk();

        [$accion, $datos] = end($registros);
        $this->assertSame('cambio_docente', $accion);
        $this->assertSame("{$this->docente->codigo} {$this->docente->nombre}", $datos['profesor_anterior']);
        $this->assertSame("{$this->otroDocente->codigo} {$this->otroDocente->nombre}", $datos['profesor_nuevo']);
        $this->assertStringContainsString($this->director->email, $datos['por']);
    }

    public function test_d_a3_no_se_elimina_con_evaluaciones_o_boletas(): void
    {
        $conEvaluacion = $this->asignar()->json('asignacion.id');
        $conBoleta = $this->asignar(['asignatura_id' => $this->lenguaje->id])->json('asignacion.id');
        $this->conEvaluacion($conEvaluacion);
        $this->conBoleta($conBoleta);

        foreach ([$conEvaluacion, $conBoleta] as $id) {
            $this->como($this->director)->deleteJson("/api/asignaciones/{$id}")->assertStatus(409)
                ->assertJsonPath('message', fn ($m) => str_contains($m, 'ya tiene evaluaciones o notas'));
        }
        $this->assertSame(2, Asignacion::count());
    }

    public function test_eliminar_asignacion_sin_registros(): void
    {
        $id = $this->asignar()->json('asignacion.id');

        $this->como($this->director)->deleteJson("/api/asignaciones/{$id}")->assertOk();
        $this->assertSame(0, Asignacion::count());
        $this->como($this->director)->deleteJson("/api/asignaciones/{$id}")->assertNotFound();
    }

    // --- Guardado de la carga de un docente ---

    public function test_carga_crea_las_marcadas_y_quita_las_desmarcadas(): void
    {
        $seccionB = $this->seccion($this->primero, null, 'B');

        $this->carga($this->docente, [[$this->seccion, $this->matematica], [$this->seccion, $this->lenguaje], [$seccionB, $this->matematica]])
            ->assertOk()->assertJsonPath('creadas', 3)->assertJsonPath('eliminadas', 0)->assertJsonCount(3, 'asignaciones');

        $this->carga($this->docente, [[$this->seccion, $this->matematica], [$seccionB, $this->lenguaje]])
            ->assertOk()->assertJsonPath('creadas', 1)->assertJsonPath('eliminadas', 2);

        $this->assertEqualsCanonicalizing(
            ["{$this->seccion->id}-{$this->matematica->id}", "{$seccionB->id}-{$this->lenguaje->id}"],
            Asignacion::all()->map(fn ($a) => "{$a->seccion_id}-{$a->asignatura_id}")->all()
        );

        // Lista vacía: se libera toda la carga del año
        $this->carga($this->docente, [])->assertOk()->assertJsonPath('eliminadas', 2);
        $this->assertSame(0, Asignacion::count());
    }

    public function test_carga_es_todo_o_nada(): void
    {
        $this->asignar(['profesor_id' => $this->otroDocente->id, 'asignatura_id' => $this->lenguaje->id])->assertCreated();
        $this->carga($this->docente, [[$this->seccion, $this->matematica]])->assertOk();

        // Lenguaje ya la imparte otro docente: no se guarda nada, ni siquiera lo que sí era válido
        $seccionB = $this->seccion($this->primero, null, 'B');
        $this->carga($this->docente, [[$seccionB, $this->matematica], [$this->seccion, $this->lenguaje]])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya la imparte'));

        $this->assertSame(1, Asignacion::where('profesor_id', $this->docente->id)->count());
        $this->assertSame($this->seccion->id, Asignacion::where('profesor_id', $this->docente->id)->value('seccion_id'));
    }

    public function test_carga_no_quita_materias_con_notas(): void
    {
        $this->carga($this->docente, [[$this->seccion, $this->matematica], [$this->seccion, $this->lenguaje]])->assertOk();
        $this->conEvaluacion(Asignacion::where('asignatura_id', $this->matematica->id)->value('id'));

        $this->carga($this->docente, [[$this->seccion, $this->lenguaje]])->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Puede cambiar el docente'));
        $this->assertSame(2, Asignacion::count());
    }

    public function test_carga_rechaza_secciones_de_otro_anio_y_datos_invalidos(): void
    {
        $seccion2027 = $this->seccion($this->primero, $this->anio('2027'));

        $this->carga($this->docente, [[$seccion2027, $this->matematica]])->assertStatus(422)->assertJsonValidationErrors('asignaciones');
        $this->como($this->director)->putJson("/api/profesores/{$this->docente->id}/carga", ['anio_lectivo_id' => $this->anio->id])
            ->assertStatus(422)->assertJsonValidationErrors('asignaciones');
        $this->como($this->director)->putJson("/api/profesores/{$this->docente->id}/carga", [
            'anio_lectivo_id' => $this->anio->id, 'asignaciones' => [['seccion_id' => 999, 'asignatura_id' => $this->matematica->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('asignaciones.0.seccion_id');
        $this->assertSame(0, Asignacion::count());
    }

    public function test_carga_permite_cambiar_la_seccion_a_otro_plan_al_quitar_el_anterior(): void
    {
        $this->carga($this->docente, [[$this->seccion, $this->matematica]])->assertOk();

        // El plan anterior deja de estar vigente y se aprueba uno nuevo para el grado
        $this->plan->update(['estado' => false]);
        $nuevo = $this->plan($this->primero, true, null, 'Plan 2026');
        $matematicaNueva = Asignatura::create(['nombre' => 'Matemática', 'plan_estudio_id' => $nuevo->id]);

        $this->carga($this->docente, [[$this->seccion, $matematicaNueva]])->assertOk()
            ->assertJsonPath('creadas', 1)->assertJsonPath('eliminadas', 1);
        $this->assertSame($nuevo->id, app(AsignacionService::class)->planDeSeccion($this->seccion->id)->id);
    }

    // --- Listado ---

    public function test_listado_del_anio_activo_con_filtros(): void
    {
        $this->asignar()->assertCreated();
        $this->asignar(['asignatura_id' => $this->lenguaje->id, 'profesor_id' => $this->otroDocente->id])->assertCreated();
        $seccion2027 = $this->seccion($this->primero, $this->anio('2027'));
        $this->asignar(['seccion_id' => $seccion2027->id])->assertCreated();

        $this->como($this->director)->getJson('/api/asignaciones')->assertOk()
            ->assertJsonPath('anio_lectivo_id', $this->anio->id)->assertJsonCount(2, 'asignaciones')
            ->assertJsonPath('asignaciones.0.asignatura.nombre', 'Lenguaje');
        $this->como($this->director)->getJson("/api/asignaciones?profesor_id={$this->otroDocente->id}")->assertJsonCount(1, 'asignaciones');
        $this->como($this->director)->getJson("/api/asignaciones?anio_lectivo_id={$seccion2027->anio_lectivo_id}")->assertJsonCount(1, 'asignaciones');
    }

    // --- Permisos y carga del docente (D-A5) ---

    public function test_solo_el_director_administra_asignaciones(): void
    {
        $docenteUser = $this->docente->user;
        foreach ([$docenteUser, $this->encargado] as $user) {
            $this->como($user)->getJson('/api/asignaciones')->assertForbidden();
            $this->como($user)->postJson('/api/asignaciones', [])->assertForbidden();
            $this->como($user)->putJson("/api/profesores/{$this->docente->id}/carga", [])->assertForbidden();
        }
        $this->assertSame(0, Asignacion::count());
    }

    public function test_d_a5_docente_consulta_solo_su_carga_del_anio_activo(): void
    {
        $this->carga($this->docente, [[$this->seccion, $this->matematica], [$this->seccion, $this->lenguaje]])->assertOk();
        $seccionB = $this->seccion($this->primero, null, 'B');
        $this->carga($this->otroDocente, [[$seccionB, $this->matematica]])->assertOk();
        $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, [
            'anio_lectivo_id' => $this->anio->id, 'seccion_id' => $this->seccion->id, 'estado' => 'aprobado',
        ]);

        $this->como($this->docente->user)->getJson('/api/docente/carga')->assertOk()
            ->assertJsonPath('profesor.codigo', $this->docente->codigo)
            // Datos del perfil del docente (solo lectura)
            ->assertJsonPath('profesor.dui', $this->docente->dui)
            ->assertJsonPath('profesor.telefono', $this->docente->telefono)
            ->assertJsonPath('profesor.direccion', 'X')
            ->assertJsonPath('profesor.fecha_nacimiento', '1990-01-01')
            ->assertJsonPath('anio_lectivo.anio', 2026)
            ->assertJsonCount(2, 'asignaciones')
            ->assertJsonCount(1, 'secciones')
            ->assertJsonPath('secciones.0.seccion.matriculados', 1)
            ->assertJsonPath('secciones.0.plan.nombre', 'Plan')
            ->assertJsonCount(2, 'secciones.0.materias')
            ->assertJsonStructure(['secciones' => [['seccion' => ['profesor_id', 'turno', 'grado']]]]);

        $this->como($this->director)->getJson('/api/docente/carga')->assertForbidden();
        $this->como($this->encargado)->getJson('/api/docente/carga')->assertForbidden();
    }

    public function test_docente_sin_perfil_o_sin_anio_abierto_ve_carga_vacia(): void
    {
        $sinPerfil = $this->usuario(User::ROL_DOCENTE);
        $this->como($sinPerfil)->getJson('/api/docente/carga')->assertOk()->assertJsonPath('profesor', null)->assertJsonCount(0, 'asignaciones');

        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->como($this->docente->user)->getJson('/api/docente/carga')->assertOk()->assertJsonPath('anio_lectivo', null);
    }

    public function test_asignaciones_con_o_sin_anio_activo(): void
    {
        // Con el año activo
        $this->carga($this->docente, [[$this->seccion, $this->matematica]])->assertOk();

        // Sin año activo (20/10/2026): se asigna igual en el año inactivo y es el predeterminado
        $this->anio->forceFill(['estado' => AnioLectivo::INACTIVO])->save();
        $this->carga($this->docente, [[$this->seccion, $this->matematica], [$this->seccion, $this->lenguaje]])->assertOk();
        $this->como($this->director)->postJson('/api/asignaciones', [
            'profesor_id' => $this->otroDocente->id, 'asignatura_id' => $this->lenguaje->id, 'seccion_id' => $this->seccion->id,
        ])->assertStatus(409);
        $this->como($this->director)->getJson('/api/asignaciones')->assertOk()
            ->assertJsonPath('anio_lectivo_id', $this->anio->id)->assertJsonCount(2, 'asignaciones');
        $this->como($this->docente->user)->getJson('/api/docente/carga')->assertOk()
            ->assertJsonPath('anio_lectivo.id', $this->anio->id)->assertJsonCount(2, 'asignaciones');

        // Un año inactivo mientras otro está activo también admite asignaciones
        $siguiente = $this->anio('2027');
        $this->anio->forceFill(['estado' => AnioLectivo::ACTIVO])->save();
        $seccion2027 = $this->seccion($this->primero, $siguiente);
        $this->carga($this->otroDocente, [[$seccion2027, $this->matematica]], $siguiente)->assertOk();
        $this->assertSame(1, Asignacion::where('seccion_id', $seccion2027->id)->count());
    }

    // --- Seeder y diagnóstico ---

    public function test_seeder_de_asignaciones_es_reproducible(): void
    {
        Asignacion::query()->delete();
        $this->seed([UsuarioPruebaSeeder::class, ProfesorPruebaSeeder::class, SeccionPruebaSeeder::class]);

        $this->seed(AsignacionPruebaSeeder::class);
        $total = Asignacion::count();
        $this->seed(AsignacionPruebaSeeder::class);

        $this->assertGreaterThan(0, $total);
        $this->assertSame($total, Asignacion::count());
        $this->assertSame([], array_filter(app(AsignacionService::class)->diagnostico()));
        $this->artisan('siga:diagnostico-asignaciones')->expectsOutput('Las asignaciones cumplen las reglas de M4.')->assertExitCode(0);
    }
}
