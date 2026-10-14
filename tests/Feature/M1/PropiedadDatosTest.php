<?php

namespace Tests\Feature\M1;

use App\Models\Padre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 15. Una cuenta ENCARGADO no puede leer ni modificar datos de otra cuenta.
 */
class PropiedadDatosTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $a;
    private User $b;
    private array $catalogos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->a = $this->usuario(User::ROL_ENCARGADO);
        $this->b = $this->usuario(User::ROL_ENCARGADO);
    }

    public function test_estudiante_ajeno_no_se_modifica(): void
    {
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);

        $datos = ['nombre' => 'Cambiado', 'fecha_nacimiento' => '2012-01-01', 'genero' => 'Masculino',
            'distrito_id' => $this->catalogos['distrito'], 'direccion' => 'Otra', 'user_id' => $this->a->id];

        $this->como($this->a)->putJson("/api/estudiantes/{$deB->id}", $datos)->assertNotFound();
        $this->assertSame($this->b->id, $deB->fresh()->user_id);
        $this->assertNotSame('Cambiado', $deB->fresh()->nombre);

        // El propietario sí puede, y el user_id del cuerpo se ignora
        $this->como($this->b)->putJson("/api/estudiantes/{$deB->id}", $datos)->assertOk();
        $this->assertSame($this->b->id, $deB->fresh()->user_id);

        // El estudiante creado queda a nombre de la cuenta autenticada
        $this->como($this->a)->postJson('/api/estudiantes', array_merge($datos, ['nombre' => 'Nuevo de A', 'user_id' => $this->b->id, 'padres' => [$this->padreRegistrado()]]))->assertCreated();
        $this->assertDatabaseHas('estudiantes', ['nombre' => 'Nuevo de A', 'user_id' => $this->a->id]);
    }

    public function test_matriculas_solo_propias(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito'], ['nie' => '1111111']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito'], ['nie' => '2222222']);
        $matA = $this->matriculaDe($deA, $this->catalogos);
        $matB = $this->matriculaDe($deB, $this->catalogos);

        $this->como($this->a)->getJson('/api/matriculas')
            ->assertOk()->assertJsonCount(1, 'matriculas')->assertJsonPath('matriculas.0.id', $matA);

        $this->como($this->a)->getJson("/api/matriculas/{$matB}")->assertNotFound();
        $this->como($this->a)->getJson("/api/matriculas/{$matA}")->assertOk();

        $encargadoB = DB::table('encargados')->insertGetId(['nombre' => 'Enc B', 'parentesco' => 'Tío(a)', 'telefono' => '22222222', 'dui' => '00000002-6', 'direccion' => 'X', 'user_id' => $this->b->id]);
        $this->como($this->a)->putJson("/api/matriculas/{$matB}", ['enfermedad_id' => $this->catalogos['enfermedad'], 'encargado_id' => $encargadoB, 'especialidad_id' => $this->catalogos['especialidad']])
            ->assertStatus(422)->assertJsonValidationErrors('encargado_id');

        $encargadoA = DB::table('encargados')->insertGetId(['nombre' => 'Enc A', 'parentesco' => 'Tío(a)', 'telefono' => '22222221', 'dui' => '00000001-8', 'direccion' => 'X', 'user_id' => $this->a->id]);
        $this->como($this->a)->putJson("/api/matriculas/{$matB}", ['enfermedad_id' => $this->catalogos['enfermedad'], 'encargado_id' => $encargadoA, 'especialidad_id' => $this->catalogos['especialidad']])
            ->assertNotFound();
        $this->como($this->a)->putJson("/api/matriculas/{$matA}", ['enfermedad_id' => $this->catalogos['enfermedad'], 'encargado_id' => $encargadoA, 'especialidad_id' => $this->catalogos['especialidad']])
            ->assertOk();

        $this->como($this->a)->postJson('/api/matriculas/buscar-nie', ['nie' => '2222222'])->assertNotFound();
        $this->como($this->a)->postJson('/api/matriculas/buscar-nie', ['nie' => '1111111'])->assertOk();
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$deB->id}/padres")->assertNotFound();
        $this->como($this->a)->getJson('/api/matriculas/estudiantes-inscritos')->assertJsonCount(1, 'estudiantes');
        $this->como($this->a)->postJson("/api/matriculas/validar-grado/{$deB->id}/1", ['ingreso' => 'Nuevo ingreso'])->assertNotFound();
        $this->como($this->a)->postJson('/api/matriculas/duplicidad', ['estudiante_id' => $deB->id])->assertNotFound();

        // Crear una matrícula para un estudiante ajeno
        Storage::fake('public');
        $this->como($this->a)->post('/api/matriculas', [
            'ingreso' => 'Nuevo ingreso', 'estudiante_id' => $deB->id, 'enfermedad_id' => $this->catalogos['enfermedad'],
            'especialidad_id' => $this->catalogos['especialidad'], 'foto' => UploadedFile::fake()->create('f.jpg', 10, 'image/jpeg'),
            'es_trasladado' => 0,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('estudiante_id');

        // El director ve todas
        $director = $this->usuario(User::ROL_DIRECTOR);
        $this->como($director)->getJson('/api/matriculas')->assertJsonCount(2, 'matriculas');
        $this->como($director)->getJson("/api/matriculas/{$matB}")->assertOk();
    }

    public function test_padres_y_parientes_solo_propios(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $padreDeB = Padre::create(['nombre' => 'Padre de B', 'dui' => '00016297-5', 'user_id' => null]);
        DB::table('parientes')->insert(['padre_id' => $padreDeB->id, 'estudiante_id' => $deB->id, 'parentesco' => 'Padre']);

        $this->como($this->a)->postJson('/api/padres/buscar-dui', ['dui' => '000162975'])->assertNotFound();
        $this->como($this->b)->postJson('/api/padres/buscar-dui', ['dui' => '000162975'])->assertOk()->assertJsonPath('padre.id', $padreDeB->id);

        $this->como($this->a)->postJson('/api/parientes', ['estudiante_id' => $deA->id, 'padre_id' => $padreDeB->id, 'parentesco' => 'Padre'])->assertNotFound();
        $this->como($this->a)->postJson('/api/parientes', ['estudiante_id' => $deB->id, 'padre_id' => $padreDeB->id, 'parentesco' => 'Padre'])->assertNotFound();

        // Padre que es la propia cuenta: un DUI ya registrado se rechaza
        $this->como($this->a)->postJson('/api/padres', ['nombre' => 'A', 'dui' => '00016297-5', 'es_encargado' => true])->assertStatus(422);
        $this->como($this->a)->postJson('/api/padres', ['nombre' => 'A', 'dui' => '000000018', 'es_encargado' => true])
            ->assertCreated()->assertJsonPath('padre.user_id', $this->a->id)->assertJsonPath('padre.dui', '00000001-8');

        // Un padre se registra ya relacionado con el estudiante propio (tabla parientes)
        $madre = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre de A', 'dui' => '00000003-4', 'es_encargado' => false,
            'estudiante_id' => $deA->id, 'parentesco' => 'Madre'])->assertCreated()->json('padre.id');
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('parientes')->where('padre_id', $madre)->where('estudiante_id', $deA->id)->count());
    }

    public function test_docente_no_accede_a_operaciones_administrativas(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE);

        $this->como($docente)->getJson('/api/matriculas')->assertStatus(403);
        $this->como($docente)->getJson('/api/profesores')->assertStatus(403);
        $this->como($docente)->postJson('/api/secciones', [])->assertStatus(403);
        $this->como($this->a)->getJson('/api/profesores')->assertStatus(403);
    }

    // La cuenta del docente se elige por correo: un user_id enviado por el cliente se ignora
    public function test_perfil_docente_usa_la_cuenta_docente_del_correo(): void
    {
        $director = $this->usuario(User::ROL_DIRECTOR);
        $docente = $this->usuario(User::ROL_DOCENTE, ['email' => 'prof@example.com']);
        $datos = ['nombre' => 'Prof', 'dui' => '000162975', 'fecha_nacimiento' => '1990-01-01',
            'telefono' => '7123-4567', 'direccion' => 'X', 'email' => 'prof@example.com'];

        $this->como($director)->postJson('/api/profesores', array_merge($datos, ['email' => $this->a->email]))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->como($director)->postJson('/api/profesores', $datos + ['user_id' => $this->a->id])
            ->assertCreated()->assertJsonPath('profesor.dui', '00016297-5')
            ->assertJsonPath('profesor.user_id', $docente->id);
    }
}
