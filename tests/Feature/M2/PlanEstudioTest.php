<?php

namespace Tests\Feature\M2;

use App\Models\Asignatura;
use App\Models\Grado;
use App\Models\PlanEstudio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.3: plan de estudios con los campos del diagrama y asignaturas únicas por plan.
 */
class PlanEstudioTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private User $director;

    private Grado $primero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->primero = Grado::create(['nombre' => 'Primer grado']);
    }

    private function datosPlan(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Plan Básica 2026',
            'especialidad_id' => null,
            'estado' => true,
            'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-11-30',
            'fecha_aprobacion' => '2025-12-01',
            'descripcion' => 'Pensum de primer grado',
        ], $cambios);
    }

    private function crearPlan(array $cambios = []): PlanEstudio
    {
        $id = $this->como($this->director)->postJson('/api/plan-estudios', $this->datosPlan($cambios))
            ->assertCreated()->json('plan.id');

        return PlanEstudio::findOrFail($id);
    }

    private function asignacionDe(Asignatura $asignatura): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE);
        $profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe '.$docente->id, 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P'.$docente->id,
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $seccion = DB::table('secciones')->insertGetId([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino',
            'grado_id' => $this->primero->id, 'profesor_id' => $profesor,
        ]);
        DB::table('asignaciones')->insert([
            'profesor_id' => $profesor, 'asignatura_id' => $asignatura->id, 'seccion_id' => $seccion,
        ]);
    }

    // --- Plan de estudios ---

    public function test_director_crea_plan_sin_especialidad_para_basica(): void
    {
        $plan = $this->crearPlan();

        $this->assertNull($plan->especialidad_id);
        $this->assertTrue($plan->estado);
    }

    public function test_plan_de_bachillerato_con_especialidad(): void
    {
        $especialidad = DB::table('especialidades')->insertGetId(['nombre' => 'Bachillerato General']);

        $plan = $this->crearPlan(['especialidad_id' => $especialidad]);

        $this->como($this->director)->getJson("/api/plan-estudios/{$plan->id}")->assertOk()
            ->assertJsonMissingPath('plan.grado_id')
            ->assertJsonPath('plan.especialidad.nombre', 'Bachillerato General');
    }

    public function test_validaciones_del_plan(): void
    {
        $this->como($this->director)->postJson('/api/plan-estudios', [])->assertStatus(422)
            ->assertJsonValidationErrors(['nombre', 'estado', 'fecha_inicio', 'fecha_fin', 'fecha_aprobacion', 'descripcion']);

        $this->como($this->director)->postJson('/api/plan-estudios', $this->datosPlan([
            'nombre' => str_repeat('a', 31), 'especialidad_id' => 999,
            'fecha_fin' => '2026-01-01', 'descripcion' => str_repeat('d', 201),
        ]))->assertStatus(422)->assertJsonValidationErrors(['nombre', 'especialidad_id', 'fecha_fin', 'descripcion']);
    }

    public function test_planes_vigentes_y_no_vigentes(): void
    {
        $vigente = $this->crearPlan();
        $this->crearPlan(['nombre' => 'Plan Básica 2027']);
        $this->crearPlan(['nombre' => 'Plan anterior', 'estado' => false]);

        $this->como($this->director)->putJson("/api/plan-estudios/{$vigente->id}", $this->datosPlan(['estado' => false]))->assertOk();

        $this->como($this->director)->getJson('/api/plan-estudios?vigente=1')->assertOk()
            ->assertJsonCount(1, 'planes')->assertJsonPath('planes.0.nombre', 'Plan Básica 2027');
        $this->como($this->director)->getJson('/api/plan-estudios?vigente=0')->assertOk()->assertJsonCount(2, 'planes');
    }

    public function test_plan_con_asignaturas_no_se_elimina(): void
    {
        $plan = $this->crearPlan();
        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id])->assertCreated();

        $this->como($this->director)->deleteJson("/api/plan-estudios/{$plan->id}")->assertStatus(409);

        $vacio = $this->crearPlan(['nombre' => 'Vacío', 'estado' => false]);
        $this->como($this->director)->deleteJson("/api/plan-estudios/{$vacio->id}")->assertOk();
        $this->assertNull(PlanEstudio::find($vacio->id));
    }

    // --- Asignaturas ---

    public function test_misma_asignatura_en_planes_distintos(): void
    {
        $plan1 = $this->crearPlan();
        $plan2 = $this->crearPlan(['nombre' => 'Plan Básica 2027']);

        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan1->id])->assertCreated();
        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan2->id])->assertCreated();
        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan1->id])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        $this->assertSame(2, Asignatura::where('nombre', 'Matemática')->count());
    }

    public function test_asignatura_exige_plan_valido(): void
    {
        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Inglés'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_estudio_id');
        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'Inglés', 'plan_estudio_id' => 999])
            ->assertStatus(422)->assertJsonValidationErrors('plan_estudio_id');
    }

    public function test_asignaturas_por_plan(): void
    {
        $vigente = $this->crearPlan();
        $anterior = $this->crearPlan(['nombre' => 'Plan 2020', 'estado' => false]);
        Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $vigente->id]);
        Asignatura::create(['nombre' => 'Matemática', 'plan_estudio_id' => $vigente->id]);
        Asignatura::create(['nombre' => 'Moral', 'plan_estudio_id' => $anterior->id]);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $nombres = $this->como($docente)->getJson("/api/asignaturas?plan_estudio_id={$vigente->id}")->assertOk()
            ->json('asignaturas.*.nombre');
        $this->assertSame(['Lenguaje', 'Matemática'], $nombres);
        $this->como($docente)->getJson('/api/asignaturas')->assertOk()->assertJsonCount(3, 'asignaturas');
    }

    public function test_asignatura_con_asignaciones_no_se_elimina_ni_cambia_de_plan(): void
    {
        $plan = $this->crearPlan();
        $otro = $this->crearPlan(['nombre' => 'Plan Básica 2027']);
        $asignatura = Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id]);
        $this->asignacionDe($asignatura);

        $this->como($this->director)->deleteJson("/api/asignaturas/{$asignatura->id}")->assertStatus(409);
        $this->como($this->director)->putJson("/api/asignaturas/{$asignatura->id}", ['nombre' => 'Lenguaje', 'plan_estudio_id' => $otro->id])
            ->assertStatus(409);
        // Corregir el nombre dentro del mismo plan sí se permite
        $this->como($this->director)->putJson("/api/asignaturas/{$asignatura->id}", ['nombre' => 'Lenguaje y Literatura', 'plan_estudio_id' => $plan->id])
            ->assertOk()->assertJsonPath('asignatura.nombre', 'Lenguaje y Literatura');
    }

    // --- Permisos ---

    public function test_solo_el_director_modifica_y_todos_con_sesion_consultan(): void
    {
        $plan = $this->crearPlan();
        $asignatura = Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id]);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $usuario = $this->usuario($rol);
            $this->como($usuario)->getJson('/api/plan-estudios')->assertOk();
            $this->como($usuario)->getJson("/api/asignaturas/{$asignatura->id}")->assertOk();
            $this->como($usuario)->postJson('/api/plan-estudios', $this->datosPlan(['estado' => false]))->assertForbidden();
            $this->como($usuario)->postJson('/api/asignaturas', ['nombre' => 'X', 'plan_estudio_id' => $plan->id])->assertForbidden();
            $this->como($usuario)->deleteJson("/api/asignaturas/{$asignatura->id}")->assertForbidden();
        }

        $this->sinSesion()->getJson('/api/plan-estudios')->assertUnauthorized();
        $this->sinSesion()->getJson('/api/asignaturas')->assertUnauthorized();
    }
}
