<?php

namespace Tests\Feature\M2;

use App\Models\Asignatura;
use App\Models\PlanAsignatura;
use App\Models\Grado;
use App\Models\PlanEstudio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.3: plan de estudios con los campos del diagrama. Diagrama ER (21/10/2026):
 * catálogo de asignaturas sin repetidos y plan_asignaturas para cada plan.
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

    private function asignacionDe(PlanAsignatura $asignatura): void
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
            'profesor_id' => $profesor, 'plan_asignatura_id' => $asignatura->id, 'seccion_id' => $seccion,
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

    public function test_plan_y_asignatura_sin_nombres_repetidos(): void
    {
        $plan = $this->crearPlan();
        $otro = $this->crearPlan(['nombre' => 'Plan Media 2026']);

        // Mayúsculas, tildes y espacios no hacen distinto un nombre
        $this->como($this->director)->postJson('/api/plan-estudios', $this->datosPlan(['nombre' => '  plan  basica 2026 ']))
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un plan de estudio con ese nombre.');
        $this->como($this->director)->putJson("/api/plan-estudios/{$otro->id}", $this->datosPlan(['nombre' => 'PLAN BÁSICA 2026']))
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->como($this->director)->putJson("/api/plan-estudios/{$plan->id}", $this->datosPlan(['descripcion' => 'Cambio']))->assertOk();

        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan->id])->assertCreated();
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => ' matematica ', 'plan_estudio_id' => $plan->id])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        // En otro plan se reutiliza la misma asignatura del catálogo
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Matematica', 'plan_estudio_id' => $otro->id])
            ->assertCreated()->assertJsonPath('plan_asignatura.nombre', 'Matemática');
        $this->assertSame(1, Asignatura::count());

        $this->como($this->director)->postJson('/api/asignaturas', ['nombre' => 'MATEMATICA'])->assertStatus(422)->assertJsonValidationErrors('nombre');
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
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id])->assertCreated();

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

        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan1->id])->assertCreated();
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan2->id])->assertCreated();
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Matemática', 'plan_estudio_id' => $plan1->id])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');

        // Una sola asignatura en el catálogo, enlazada a los dos planes
        $this->assertSame(1, Asignatura::where('nombre', 'Matemática')->count());
        $this->assertSame(2, PlanAsignatura::count());
        $this->como($this->director)->getJson('/api/asignaturas')->assertOk()->assertJsonCount(2, 'asignaturas.0.planes');
    }

    public function test_asignatura_exige_plan_valido(): void
    {
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Inglés'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_estudio_id');
        $this->como($this->director)->postJson('/api/plan-asignaturas', ['nombre' => 'Inglés', 'plan_estudio_id' => 999])
            ->assertStatus(422)->assertJsonValidationErrors('plan_estudio_id');
    }

    public function test_asignaturas_por_plan(): void
    {
        $vigente = $this->crearPlan();
        $anterior = $this->crearPlan(['nombre' => 'Plan 2020', 'estado' => false]);
        PlanAsignatura::enlazar($vigente->id, 'Lenguaje');
        PlanAsignatura::enlazar($vigente->id, 'Matemática');
        PlanAsignatura::enlazar($anterior->id, 'Moral');
        PlanAsignatura::enlazar($anterior->id, 'Lenguaje');

        $docente = $this->usuario(User::ROL_DOCENTE);
        $nombres = $this->como($docente)->getJson("/api/plan-asignaturas?plan_estudio_id={$vigente->id}")->assertOk()
            ->json('plan_asignaturas.*.nombre');
        $this->assertSame(['Lenguaje', 'Matemática'], $nombres);
        $this->como($docente)->getJson('/api/plan-asignaturas')->assertOk()->assertJsonCount(4, 'plan_asignaturas');
        $this->como($docente)->getJson('/api/asignaturas')->assertOk()->assertJsonCount(3, 'asignaturas');
    }

    public function test_asignatura_con_asignaciones_no_se_quita_del_plan(): void
    {
        $plan = $this->crearPlan();
        $otro = $this->crearPlan(['nombre' => 'Plan Básica 2027']);
        $materia = PlanAsignatura::enlazar($plan->id, 'Lenguaje');
        $enOtro = PlanAsignatura::enlazar($otro->id, 'Lenguaje');
        $this->asignacionDe($materia);

        $this->como($this->director)->deleteJson("/api/plan-asignaturas/{$materia->id}")->assertStatus(409);
        $this->como($this->director)->deleteJson("/api/asignaturas/{$materia->asignatura_id}")->assertStatus(409);

        // Renombrar en un plan no cambia la materia del otro plan que la comparte
        $this->como($this->director)->putJson("/api/plan-asignaturas/{$materia->id}", ['nombre' => 'Lenguaje y Literatura'])
            ->assertOk()->assertJsonPath('plan_asignatura.nombre', 'Lenguaje y Literatura');
        $this->assertSame('Lenguaje', $enOtro->fresh()->asignatura->nombre);

        // Sin docentes asignados sí se quita; la asignatura queda mientras otro plan la use
        $this->como($this->director)->deleteJson("/api/plan-asignaturas/{$enOtro->id}")->assertOk();
        $this->assertNull(Asignatura::where('nombre', 'Lenguaje')->first());
        $this->assertNotNull(Asignatura::where('nombre', 'Lenguaje y Literatura')->first());
    }

    // --- Permisos ---

    public function test_solo_el_director_modifica_y_todos_con_sesion_consultan(): void
    {
        $plan = $this->crearPlan();
        $asignatura = PlanAsignatura::enlazar($plan->id, 'Lenguaje');

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $usuario = $this->usuario($rol);
            $this->como($usuario)->getJson('/api/plan-estudios')->assertOk();
            $this->como($usuario)->getJson("/api/asignaturas/{$asignatura->asignatura_id}")->assertOk();
            $this->como($usuario)->getJson("/api/plan-asignaturas/{$asignatura->id}")->assertOk();
            $this->como($usuario)->postJson('/api/plan-estudios', $this->datosPlan(['estado' => false]))->assertForbidden();
            $this->como($usuario)->postJson('/api/asignaturas', ['nombre' => 'X'])->assertForbidden();
            $this->como($usuario)->postJson('/api/plan-asignaturas', ['nombre' => 'X', 'plan_estudio_id' => $plan->id])->assertForbidden();
            $this->como($usuario)->deleteJson("/api/plan-asignaturas/{$asignatura->id}")->assertForbidden();
            $this->como($usuario)->deleteJson("/api/asignaturas/{$asignatura->asignatura_id}")->assertForbidden();
        }

        $this->sinSesion()->getJson('/api/plan-estudios')->assertUnauthorized();
        $this->sinSesion()->getJson('/api/asignaturas')->assertUnauthorized();
    }
}
