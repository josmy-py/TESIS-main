<?php

namespace Tests\Feature\M2;

use App\Models\Discapacidad;
use App\Models\Enfermedad;
use App\Models\Especialidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\Concerns\HoyFijo;
use Tests\TestCase;

/**
 * M2.1.2 especialidades, M2.1.3 discapacidades y M2.1.4 enfermedades.
 */
class CatalogosApoyoTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase, HoyFijo;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    // --- Especialidades ---

    public function test_especialidad_respeta_el_largo_de_la_columna(): void
    {
        $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Bachillerato General'])->assertCreated();
        $this->como($this->director)->postJson('/api/especialidades', ['nombre' => str_repeat('a', 51)])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Bachillerato General'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
    }

    public function test_especialidad_sin_nombres_repetidos(): void
    {
        $id = $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Informática'])->assertCreated()->json('especialidad.id');

        foreach (['informatica', '  INFORMÁTICA ', 'Informática'] as $nombre) {
            $this->como($this->director)->postJson('/api/especialidades', ['nombre' => $nombre])
                ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe una especialidad con ese nombre.');
        }
        $otra = $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Contaduría'])->assertCreated()->json('especialidad.id');
        $this->como($this->director)->putJson("/api/especialidades/{$otra}", ['nombre' => 'INFORMATICA'])->assertStatus(422);
        // Guardar con su propio nombre no es duplicado
        $this->como($this->director)->putJson("/api/especialidades/{$id}", ['nombre' => 'Informática'])->assertOk();
        $this->assertSame(2, Especialidad::count());
    }

    public function test_especialidades_se_bloquean_cuando_el_anio_activo_comenzo(): void
    {
        $id = Especialidad::create(['nombre' => 'General'])->id;
        $anio = \App\Models\AnioLectivo::create(['anio' => 2027, 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $anio->forceFill(['estado' => 'activo'])->save();

        // Activo pero sus fechas aún no comienzan (hoy 01/12/2025): se puede modificar (20/10/2026)
        $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Nueva'])->assertCreated();
        $this->como($this->director)->putJson("/api/especialidades/{$id}", ['nombre' => 'Renombrada'])->assertOk();

        // Desde la fecha de inicio del año activo queda bloqueado
        $this->fijarHoy('2027-01-15');
        $bloqueo = 'El año lectivo activo (2027) ya comenzó (15/01/2027): las especialidades no se pueden crear, modificar ni eliminar hasta que se cierre.';
        $this->como($this->director)->postJson('/api/especialidades', ['nombre' => 'Otra nueva'])->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->como($this->director)->putJson("/api/especialidades/{$id}", ['nombre' => 'Otra'])->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->como($this->director)->deleteJson("/api/especialidades/{$id}")->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->como($this->director)->getJson('/api/especialidades')->assertOk()->assertJsonCount(2, 'especialidades');
        $this->assertSame('Renombrada', Especialidad::find($id)->nombre);

        // Un año inactivo ya comenzado no bloquea: solo cuenta el activo
        $anio->forceFill(['estado' => 'inactivo'])->save();
        $this->como($this->director)->putJson("/api/especialidades/{$id}", ['nombre' => 'Otra'])->assertOk();

        // Cerrado el año, se puede volver a modificar
        $anio->forceFill(['estado' => 'cierre'])->save();
        $this->como($this->director)->putJson("/api/especialidades/{$id}", ['nombre' => 'Otra más'])->assertOk();
    }

    public function test_especialidad_con_matriculas_o_planes_no_se_elimina(): void
    {
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $this->matriculaDe($this->estudianteDe($encargado, $catalogos['distrito']), $catalogos);

        $this->como($this->director)->deleteJson("/api/especialidades/{$catalogos['especialidad']}")->assertStatus(409);

        $conPlan = Especialidad::create(['nombre' => 'Técnico']);
        DB::table('plan_estudios')->insert([
            'nombre' => 'Plan 2026', 'especialidad_id' => $conPlan->id, 'estado' => true,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2026-11-30', 'descripcion' => 'X', 'fecha_aprobacion' => '2025-12-01',
        ]);
        $this->como($this->director)->deleteJson("/api/especialidades/{$conPlan->id}")->assertStatus(409)
            ->assertJsonPath('message', 'No se puede eliminar la especialidad porque tiene planes de estudio asociados.');
        $this->assertSame(2, Especialidad::count());

        $libre = Especialidad::create(['nombre' => 'Libre']);
        $this->como($this->director)->deleteJson("/api/especialidades/{$libre->id}")->assertOk();
        $this->assertNull(Especialidad::find($libre->id));
    }

    // --- Discapacidades ---

    public function test_discapacidad_sin_expedientes_se_elimina(): void
    {
        $discapacidad = Discapacidad::create(['nombre' => 'Visual']);

        $this->como($this->director)->deleteJson("/api/discapacidades/{$discapacidad->id}")->assertOk();
        $this->assertNull(Discapacidad::find($discapacidad->id));
        $this->como($this->director)->deleteJson("/api/discapacidades/{$discapacidad->id}")->assertNotFound();
    }

    public function test_discapacidad_en_expedientes_devuelve_conflicto_y_no_borra_nada(): void
    {
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $matricula = $this->matriculaDe($this->estudianteDe($encargado, $catalogos['distrito']), $catalogos);
        $discapacidad = Discapacidad::create(['nombre' => 'Auditiva']);
        DB::table('expedientes')->insert(['discapacidad_id' => $discapacidad->id, 'matricula_id' => $matricula]);

        $this->como($this->director)->deleteJson("/api/discapacidades/{$discapacidad->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'No se puede eliminar la discapacidad porque está registrada en expedientes de estudiantes');

        $this->assertNotNull(Discapacidad::find($discapacidad->id));
        $this->assertSame(1, DB::table('expedientes')->count());
        $this->assertSame(1, DB::table('matriculas')->count());
    }

    public function test_discapacidades_solo_el_director_escribe(): void
    {
        $discapacidad = Discapacidad::create(['nombre' => 'Motora']);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/discapacidades')->assertOk();
            $this->como($cuenta)->deleteJson("/api/discapacidades/{$discapacidad->id}")->assertStatus(403);
        }
        // Las operaciones existentes siguen igual
        $this->como($this->director)->putJson("/api/discapacidades/{$discapacidad->id}", ['nombre' => 'Motriz'])
            ->assertOk()->assertJsonPath('discapacidades.nombre', 'Motriz');
    }

    // --- Enfermedades ---

    public function test_enfermedad_crud_completo(): void
    {
        $id = $this->como($this->director)->postJson('/api/enfermedades', ['nombre' => 'Asma', 'medicamento' => 'Salbutamol'])
            ->assertCreated()->assertJsonPath('enfermedad.nombre', 'Asma')->json('enfermedad.id');

        $this->como($this->director)->getJson('/api/enfermedades')->assertOk()->assertJsonPath('enfermedades.0.medicamento', 'Salbutamol');
        $this->como($this->director)->getJson("/api/enfermedades/{$id}")->assertOk()->assertJsonPath('enfermedad.id', $id);

        $this->como($this->director)->putJson("/api/enfermedades/{$id}", ['nombre' => 'Asma bronquial', 'medicamento' => 'Inhalador'])
            ->assertOk()->assertJsonPath('enfermedad.medicamento', 'Inhalador');

        $this->como($this->director)->deleteJson("/api/enfermedades/{$id}")->assertOk();
        $this->assertNull(Enfermedad::find($id));
        $this->como($this->director)->getJson("/api/enfermedades/{$id}")->assertNotFound();
        $this->como($this->director)->putJson("/api/enfermedades/{$id}", ['nombre' => 'X', 'medicamento' => 'Y'])->assertNotFound();
    }

    public function test_enfermedad_validacion(): void
    {
        Enfermedad::create(['nombre' => 'Diabetes', 'medicamento' => 'Insulina']);

        $this->como($this->director)->postJson('/api/enfermedades', [])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'medicamento']);
        $this->como($this->director)->postJson('/api/enfermedades', ['nombre' => 'Diabetes', 'medicamento' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->como($this->director)->postJson('/api/enfermedades', ['nombre' => str_repeat('a', 51), 'medicamento' => str_repeat('b', 51)])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre', 'medicamento']);
        $this->assertSame(1, Enfermedad::count());
    }

    public function test_enfermedad_en_matriculas_devuelve_conflicto_y_no_toca_la_matricula(): void
    {
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $matricula = $this->matriculaDe($this->estudianteDe($encargado, $catalogos['distrito']), $catalogos);
        $antes = (array) DB::table('matriculas')->find($matricula);

        $this->como($this->director)->deleteJson("/api/enfermedades/{$catalogos['enfermedad']}")
            ->assertStatus(409)->assertJsonPath('message', 'No se puede eliminar la enfermedad porque está registrada en matrículas.');

        $this->assertNotNull(Enfermedad::find($catalogos['enfermedad']));
        $this->assertSame($antes, (array) DB::table('matriculas')->find($matricula));
    }

    public function test_enfermedades_lectura_autenticada_y_escritura_solo_director(): void
    {
        $enfermedad = Enfermedad::create(['nombre' => 'Epilepsia', 'medicamento' => 'Valproato']);

        foreach ([User::ROL_DOCENTE, User::ROL_ENCARGADO] as $rol) {
            $cuenta = $this->usuario($rol);
            $this->como($cuenta)->getJson('/api/enfermedades')->assertOk();
            $this->como($cuenta)->getJson("/api/enfermedades/{$enfermedad->id}")->assertOk();
            $this->como($cuenta)->postJson('/api/enfermedades', ['nombre' => 'X', 'medicamento' => 'Y'])->assertStatus(403);
            $this->como($cuenta)->putJson("/api/enfermedades/{$enfermedad->id}", ['nombre' => 'X', 'medicamento' => 'Y'])->assertStatus(403);
            $this->como($cuenta)->deleteJson("/api/enfermedades/{$enfermedad->id}")->assertStatus(403);
        }

        $this->sinSesion()->getJson('/api/enfermedades')->assertStatus(401);
        $this->assertSame('Epilepsia', $enfermedad->fresh()->nombre);
    }

    public function test_error_interno_no_expone_detalles(): void
    {
        \Illuminate\Support\Facades\Schema::rename('enfermedades', 'enfermedades_tmp');

        $respuesta = $this->como($this->director)->getJson('/api/enfermedades')->assertStatus(500);
        $this->assertSame(['message' => 'Error al obtener las enfermedades'], $respuesta->json());

        \Illuminate\Support\Facades\Schema::rename('enfermedades_tmp', 'enfermedades');
    }
}
