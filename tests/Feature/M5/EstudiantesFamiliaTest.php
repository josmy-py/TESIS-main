<?php

namespace Tests\Feature\M5;

use App\Models\AnioLectivo;
use App\Models\Encargado;
use App\Models\Estudiante;
use App\Models\Padre;
use App\Models\User;
use Database\Seeders\FamiliaPruebaSeeder;
use Database\Seeders\UsuarioPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M5: estudiantes y familia (D3, D-E1 a D-E8 con los ajustes de D-E4 y D-E7).
 */
class EstudiantesFamiliaTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $a;
    private User $b;
    private User $director;
    private array $catalogos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->a = $this->usuario(User::ROL_ENCARGADO);
        $this->b = $this->usuario(User::ROL_ENCARGADO);
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    private function datosEstudiante(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Luis Pérez', 'fecha_nacimiento' => '2015-03-10', 'genero' => 'Masculino',
            'distrito_id' => $this->catalogos['distrito'], 'direccion' => 'Calle 1', 'nie' => null,
            'padres' => [$this->padreRegistrado()],
        ], $cambios);
    }

    private function padreDe(Estudiante $estudiante, string $dui, string $parentesco = 'Madre', array $datos = []): Padre
    {
        $padre = Padre::create(array_merge(['nombre' => "{$parentesco} de {$estudiante->nombre}", 'dui' => $dui, 'telefono' => '7777-0000'], $datos));
        DB::table('parientes')->insert(['padre_id' => $padre->id, 'estudiante_id' => $estudiante->id, 'parentesco' => $parentesco]);

        return $padre;
    }

    private function anio(string $estado = AnioLectivo::ACTIVO): int
    {
        return DB::table('anios_lectivos')->insertGetId(['nombre' => 'Año '.uniqid(), 'estado' => $estado]);
    }

    // --- Estudiantes ---

    public function test_listado_y_ficha_solo_de_la_propia_cuenta(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito'], ['nombre' => 'Ana López', 'nie' => '5550001']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito'], ['nombre' => 'Beto Ruiz', 'nie' => '5550002']);
        $this->padreDe($deA, '00000003-4');

        $this->como($this->a)->getJson('/api/estudiantes')->assertOk()
            ->assertJsonCount(1, 'estudiantes')->assertJsonPath('estudiantes.0.id', $deA->id)
            ->assertJsonPath('estudiantes.0.padres_count', 1)
            ->assertJsonMissingPath('estudiantes.0.user');
        $this->como($this->a)->getJson("/api/estudiantes/{$deB->id}")->assertNotFound();
        $this->como($this->a)->getJson("/api/estudiantes/{$deA->id}")->assertOk()
            ->assertJsonPath('estudiante.padres.0.pivot.parentesco', 'Madre')
            ->assertJsonPath('permisos.editar_nie', true);

        // El director ve a todos, con la cuenta responsable, y busca por nombre o NIE
        $this->como($this->director)->getJson('/api/estudiantes')->assertJsonCount(2, 'estudiantes')
            ->assertJsonPath('estudiantes.0.user.email', $this->a->email);
        $this->como($this->director)->getJson('/api/estudiantes?q=Beto')->assertJsonCount(1, 'estudiantes');
        $this->como($this->director)->getJson('/api/estudiantes?q=5550001')->assertJsonPath('estudiantes.0.id', $deA->id);

        // El docente no accede a datos de estudiantes en M5 (D-E8)
        $docente = $this->usuario(User::ROL_DOCENTE);
        $this->como($docente)->getJson('/api/estudiantes')->assertForbidden();
        $this->como($docente)->getJson("/api/estudiantes/{$deA->id}")->assertForbidden();
        $this->como($docente)->getJson('/api/padres')->assertForbidden();
    }

    // Primero se registran los padres; al guardar al estudiante se indican por DUI y se crea la relación
    public function test_estudiante_se_registra_con_padres_ya_registrados(): void
    {
        $sinPadres = $this->datosEstudiante();
        unset($sinPadres['padres']);
        $this->como($this->a)->postJson('/api/estudiantes', $sinPadres)->assertStatus(422)->assertJsonValidationErrors('padres');
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['padres' => [['dui' => '00016297-5', 'parentesco' => 'Padre']]]))
            ->assertStatus(422)->assertJsonValidationErrors('padres.0.dui');
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'Luis 2']))
            ->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertSame(0, Estudiante::count());

        $padre = $this->padreRegistrado('Padre');
        $madre = $this->padreRegistrado('Madre');
        $id = $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['padres' => [$padre, $madre]]))
            ->assertCreated()->json('estudiante.id');
        $this->assertSame(['Madre', 'Padre'], DB::table('parientes')->where('estudiante_id', $id)->orderBy('parentesco')->pluck('parentesco')->all());

        // Esos padres ya son de la familia de A: B no los usa
        $this->como($this->b)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'Otro', 'padres' => [$padre]]))
            ->assertStatus(422)->assertJsonValidationErrors('padres.0.dui');
        $this->assertSame(1, Estudiante::count());
    }

    public function test_nie_opcional_numerico_y_unico(): void
    {
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante())->assertCreated()->assertJsonPath('estudiante.nie', null);
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'X', 'nie' => 'AB12']))
            ->assertStatus(422)->assertJsonValidationErrors('nie');
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'X', 'nie' => '12345678901']))
            ->assertStatus(422)->assertJsonValidationErrors('nie');
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'Con NIE', 'nie' => '7654321']))->assertCreated();
        $this->como($this->b)->postJson('/api/estudiantes', $this->datosEstudiante(['nombre' => 'Otro', 'nie' => '7654321']))
            ->assertStatus(422)->assertJsonPath('errors.nie.0', 'El NIE ya está registrado; contacte a la dirección.');
    }

    public function test_nie_solo_lo_cambia_la_direccion_despues_de_aprobar_la_matricula(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito'], ['nie' => '1000001']);
        $datos = $this->datosEstudiante(['nombre' => 'Estudiante Uno', 'nie' => '1000002']);

        // Antes de la aprobación el encargado lo corrige
        $this->matriculaDe($estudiante, $this->catalogos, ['estado' => 'pendiente']);
        $this->como($this->a)->putJson("/api/estudiantes/{$estudiante->id}", $datos)->assertOk();

        DB::table('matriculas')->update(['estado' => 'aprobado']);
        $this->como($this->a)->getJson("/api/estudiantes/{$estudiante->id}")->assertJsonPath('permisos.editar_nie', false);
        $this->como($this->a)->putJson("/api/estudiantes/{$estudiante->id}", array_merge($datos, ['nie' => '1000003']))
            ->assertStatus(422)->assertJsonValidationErrors('nie');
        // Los demás datos sí se pueden editar si el NIE no cambia
        $this->como($this->a)->putJson("/api/estudiantes/{$estudiante->id}", array_merge($datos, ['direccion' => 'Nueva']))->assertOk();
        $this->como($this->director)->putJson("/api/estudiantes/{$estudiante->id}", array_merge($datos, ['nie' => '1000003']))->assertOk();
        $this->assertSame('1000003', $estudiante->fresh()->nie);
    }

    public function test_duplicado_sin_nie_no_revela_la_cuenta(): void
    {
        $this->como($this->a)->postJson('/api/estudiantes', $this->datosEstudiante())->assertCreated();

        $respuesta = $this->como($this->b)->postJson('/api/estudiantes', $this->datosEstudiante())->assertStatus(409);
        $respuesta->assertJsonPath('message', 'El estudiante ya está registrado; contacte a la dirección.');
        $this->assertStringNotContainsString($this->a->email, $respuesta->getContent());
    }

    public function test_eliminar_solo_sin_matriculas(): void
    {
        $sinMatricula = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->padreDe($sinMatricula, '00000003-4');
        $conMatricula = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->matriculaDe($conMatricula, $this->catalogos);

        $this->como($this->b)->deleteJson("/api/estudiantes/{$sinMatricula->id}")->assertNotFound();
        $this->como($this->a)->deleteJson("/api/estudiantes/{$conMatricula->id}")->assertStatus(409);
        $this->como($this->director)->deleteJson("/api/estudiantes/{$conMatricula->id}")->assertStatus(409);
        $this->como($this->a)->deleteJson("/api/estudiantes/{$sinMatricula->id}")->assertOk();

        $this->assertNull($sinMatricula->fresh());
        $this->assertSame(0, DB::table('parientes')->where('estudiante_id', $sinMatricula->id)->count());
        $this->assertSame(1, Padre::count()); // la persona se conserva
    }

    // --- Padres y parentescos ---

    public function test_padres_solo_de_la_familia_y_sin_estudiantes_ajenos(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito'], ['nombre' => 'Hijo de A']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito'], ['nombre' => 'Hijo de B']);
        $madre = $this->padreDe($deA, '00000003-4');
        $this->padreDe($deB, '00000004-2', 'Padre');

        // La dirección autoriza que la madre también sea de la familia B
        $this->como($this->director)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '000000034', 'parentesco' => 'Madre'])->assertCreated();

        $lista = $this->como($this->b)->getJson('/api/padres')->assertOk()->assertJsonCount(2, 'padres');
        $visibles = collect($lista->json('padres'))->pluck('estudiantes')->flatten(1)->pluck('id')->unique()->values()->all();
        $this->assertSame([$deB->id], $visibles); // no se muestran estudiantes de otra familia
        $this->como($this->a)->getJson('/api/padres')->assertJsonCount(1, 'padres')->assertJsonCount(1, 'padres.0.estudiantes');
        $this->como($this->a)->getJson('/api/padres/'.Padre::where('dui', '00000004-2')->value('id'))->assertNotFound();
        $this->como($this->director)->getJson('/api/padres')->assertJsonCount(2, 'padres');
        $this->como($this->director)->getJson("/api/padres/{$madre->id}")->assertJsonCount(2, 'padre.estudiantes');
    }

    public function test_persona_de_otra_familia_requiere_autorizacion_de_la_direccion(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $madre = $this->padreDe($deA, '00000003-4', 'Madre', ['nombre' => 'Marta Secreta', 'telefono' => '7123-4567']);

        // Registrar de nuevo el mismo DUI: pide autorización y no muestra datos
        $nuevo = $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Otra', 'dui' => '000000034', 'es_encargado' => false,
            'estudiante_id' => $deB->id, 'parentesco' => 'Madre'])->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->assertStringContainsString('autorización de la dirección', $nuevo->json('errors.dui.0'));

        // Vincular por DUI: misma respuesta exista o no la persona
        $existente = $this->como($this->b)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000003-4', 'parentesco' => 'Madre'])->assertStatus(409);
        $inexistente = $this->como($this->b)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000005-0', 'parentesco' => 'Madre'])->assertStatus(409);
        $this->assertSame($existente->json('message'), $inexistente->json('message'));
        foreach (['Marta Secreta', '7123-4567', 'padre'] as $dato) {
            $this->assertStringNotContainsString($dato, $existente->getContent());
        }
        $this->assertSame(0, DB::table('parientes')->where('estudiante_id', $deB->id)->count());

        // Con la autorización de la dirección queda vinculada sin duplicar a la persona
        $this->como($this->director)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000005-0', 'parentesco' => 'Madre'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->como($this->director)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000003-4', 'parentesco' => 'Madre'])
            ->assertCreated()->assertJsonPath('padre.id', $madre->id);
        $this->assertSame(1, Padre::where('dui', '00000003-4')->count());
        $this->como($this->b)->getJson("/api/estudiantes/{$deB->id}")->assertJsonPath('estudiante.padres.0.nombre', 'Marta Secreta');
    }

    public function test_registro_propio_no_toma_registros_ajenos(): void
    {
        // A registró como padre de su hijo a una persona; B no puede registrarse con ese DUI
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $persona = $this->padreDe($deA, '00000002-6', 'Padre', ['nombre' => 'Persona B']);

        $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Persona B', 'dui' => '000000026', 'es_encargado' => true,
            'estudiante_id' => $deB->id, 'parentesco' => 'Padre'])->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->assertNull($persona->fresh()->user_id);

        // Con un DUI nuevo queda como su propio registro, una sola vez
        $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Cuenta B', 'dui' => '00000005-0', 'es_encargado' => true,
            'estudiante_id' => $deB->id, 'parentesco' => 'Padre'])->assertCreated()->assertJsonPath('padre.user_id', $this->b->id);
        $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Otra vez', 'dui' => '00000009-2', 'es_encargado' => true])->assertStatus(409);

        // Otra cuenta no puede vincular a un registro ajeno
        $deA2 = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->padreDe($deA2, '00000004-2', 'Madre');
        $this->como($this->b)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000004-2', 'parentesco' => 'Madre'])->assertStatus(409);
    }

    public function test_un_padre_y_una_madre_por_estudiante(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $base = ['es_encargado' => false, 'estudiante_id' => $estudiante->id];

        $this->como($this->a)->postJson('/api/padres', $base + ['nombre' => 'Madre', 'dui' => '00000003-4', 'parentesco' => 'Madre'])->assertCreated();
        $this->como($this->a)->postJson('/api/padres', $base + ['nombre' => 'Otra madre', 'dui' => '00000004-2', 'parentesco' => 'Madre'])
            ->assertStatus(422)->assertJsonValidationErrors('parentesco');
        $this->assertSame(1, Padre::count()); // transacción: no queda la persona a medias
        $this->como($this->a)->postJson("/api/estudiantes/{$estudiante->id}/padres", ['dui' => '00000003-4', 'parentesco' => 'Padre'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->como($this->a)->postJson('/api/padres', $base + ['nombre' => 'Padre', 'dui' => '00000004-2', 'parentesco' => 'Padre'])->assertCreated();

        // Hermano: la misma madre se vincula sin duplicarla
        $hermano = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->como($this->a)->postJson("/api/estudiantes/{$hermano->id}/padres", ['dui' => '000000034', 'parentesco' => 'Madre'])->assertCreated();
        $this->assertSame(2, Padre::count());
    }

    public function test_edicion_de_padres_segun_la_familia(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $madre = $this->padreDe($deA, '00000003-4');
        $datos = ['nombre' => 'Madre corregida', 'dui' => '00000003-4', 'telefono' => '7000-1111', 'email' => 'madre@correo.test'];

        $this->como($this->a)->getJson('/api/padres')->assertJsonPath('padres.0.editable', true);
        $this->como($this->a)->putJson("/api/padres/{$madre->id}", $datos)->assertOk()->assertJsonPath('padre.nombre', 'Madre corregida');
        $this->como($this->b)->putJson("/api/padres/{$madre->id}", $datos)->assertNotFound();

        // Compartida con otra familia: solo la dirección la modifica
        $this->como($this->director)->postJson("/api/estudiantes/{$deB->id}/padres", ['dui' => '00000003-4', 'parentesco' => 'Madre'])->assertCreated();
        $this->como($this->a)->getJson('/api/padres')->assertJsonPath('padres.0.editable', false);
        $this->como($this->a)->putJson("/api/padres/{$madre->id}", $datos)->assertStatus(409);
        $this->como($this->director)->putJson("/api/padres/{$madre->id}", array_merge($datos, ['nombre' => 'Por dirección']))->assertOk();

        // Con cuenta propia: solo esa cuenta lo edita y el DUI no se repite
        $propio = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Cuenta A', 'dui' => '00000001-8', 'es_encargado' => true])->json('padre.id');
        $this->como($this->a)->putJson("/api/padres/{$propio}", ['nombre' => 'Cuenta A editada', 'dui' => '00000001-8'])->assertOk();
        $this->como($this->a)->putJson("/api/padres/{$propio}", ['nombre' => 'Cuenta A', 'dui' => '00000003-4'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');
        $this->como($this->b)->putJson("/api/padres/{$propio}", ['nombre' => 'Cuenta A', 'dui' => '00000001-8'])->assertNotFound();
    }

    public function test_desvincular_protege_relaciones_vigentes(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $propio = Padre::create(['nombre' => 'Cuenta A', 'dui' => '00000001-8', 'user_id' => $this->a->id]);
        DB::table('parientes')->insert(['padre_id' => $propio->id, 'estudiante_id' => $estudiante->id, 'parentesco' => 'Padre']);
        $madre = $this->padreDe($estudiante, '00000003-4');

        // La persona de la cuenta responsable no se desvincula
        $this->como($this->a)->deleteJson("/api/estudiantes/{$estudiante->id}/padres/{$propio->id}")->assertStatus(409);
        $this->como($this->b)->deleteJson("/api/estudiantes/{$estudiante->id}/padres/{$madre->id}")->assertNotFound();

        // Con dos padres, quitar uno no deja la matrícula sin responsable
        $otro = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $soloMadre = $this->padreDe($otro, '00000004-2');
        $anio = $this->anio();
        $matricula = $this->matriculaDe($otro, $this->catalogos, ['anio_lectivo_id' => $anio, 'estado' => 'aprobado']);
        $this->como($this->a)->deleteJson("/api/estudiantes/{$otro->id}/padres/{$soloMadre->id}")->assertStatus(409);
        $this->como($this->director)->deleteJson("/api/estudiantes/{$otro->id}/padres/{$soloMadre->id}")->assertStatus(409);

        // Con otro encargado responsable de la matrícula, o con el año cerrado, sí se puede
        $encargado = Encargado::create(['nombre' => 'Abuela', 'parentesco' => 'Abuelo(a)', 'telefono' => '7000-0000', 'dui' => '00000005-0', 'direccion' => 'X']);
        DB::table('matriculas')->where('id', $matricula)->update(['encargado_id' => $encargado->id]);
        $this->como($this->a)->deleteJson("/api/estudiantes/{$otro->id}/padres/{$soloMadre->id}")->assertOk();

        $this->como($this->a)->deleteJson("/api/estudiantes/{$estudiante->id}/padres/{$madre->id}")->assertOk();
        $this->assertSame(1, DB::table('parientes')->where('estudiante_id', $estudiante->id)->count());
        $this->assertNotNull($madre->fresh()); // la persona se conserva
    }

    public function test_desvincular_con_anio_cerrado(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $madre = $this->padreDe($estudiante, '00000003-4');
        $this->matriculaDe($estudiante, $this->catalogos, ['anio_lectivo_id' => $this->anio(AnioLectivo::CIERRE), 'estado' => 'aprobado']);

        $this->como($this->a)->deleteJson("/api/estudiantes/{$estudiante->id}/padres/{$madre->id}")->assertOk();
    }

    // --- Encargados ---

    public function test_encargado_registra_solo_su_propio_perfil(): void
    {
        $datos = ['nombre' => 'Tía A', 'parentesco' => 'Tío(a)', 'telefono' => '7000-0001', 'direccion' => 'Calle 2'];

        $this->como($this->a)->postJson('/api/encargados', $datos + ['dui' => '000000018'])->assertCreated()
            ->assertJsonPath('encargado.user_id', $this->a->id)->assertJsonPath('encargado.dui', '00000001-8');
        $this->como($this->a)->postJson('/api/encargados', $datos + ['dui' => '00000009-2'])->assertStatus(409);
        $this->como($this->a)->postJson('/api/encargados', array_merge($datos, ['telefono' => '1234']) + ['dui' => '00000001-8'])
            ->assertStatus(422)->assertJsonValidationErrors('telefono');

        $this->como($this->a)->getJson('/api/familia/perfil')->assertOk()
            ->assertJsonPath('encargado.nombre', 'Tía A')->assertJsonPath('padre', null)->assertJsonPath('cuenta.email', $this->a->email);
    }

    public function test_encargados_visibles_y_registro_de_la_direccion(): void
    {
        $datos = ['parentesco' => 'Abuelo(a)', 'telefono' => '7000-0001', 'direccion' => 'Calle 2'];

        // Cada cuenta ENCARGADO registra su propio perfil; la dirección registra personas sin cuenta
        $deB = $this->como($this->b)->postJson('/api/encargados', $datos + ['nombre' => 'Persona B', 'dui' => '000000026'])
            ->assertCreated()->assertJsonPath('encargado.user_id', $this->b->id)->json('encargado.id');
        $this->como($this->director)->postJson('/api/encargados', $datos + ['nombre' => 'Repetido', 'dui' => '00000002-6'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');
        $sinCuenta = $this->como($this->director)->postJson('/api/encargados', $datos + ['nombre' => 'Abuela', 'dui' => '00000003-4'])
            ->assertCreated()->assertJsonPath('encargado.user_id', null)->json('encargado.id');

        // A ve a la abuela solo cuando es responsable de una matrícula de su estudiante
        $this->como($this->a)->getJson('/api/encargados')->assertJsonCount(0, 'encargados');
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $matricula = $this->matriculaDe($estudiante, $this->catalogos, ['encargado_id' => $sinCuenta]);
        $this->como($this->a)->getJson('/api/encargados')->assertJsonCount(1, 'encargados');
        $this->como($this->a)->getJson("/api/encargados/{$deB}")->assertNotFound();
        $this->como($this->a)->putJson("/api/encargados/{$sinCuenta}", $datos + ['nombre' => 'Cambio', 'dui' => '00000003-4'])->assertStatus(409);
        $this->como($this->b)->putJson("/api/encargados/{$deB}", $datos + ['nombre' => 'Persona B editada', 'dui' => '00000002-6'])->assertOk();

        // Eliminar: solo la dirección y sin matrículas
        $this->como($this->a)->deleteJson("/api/encargados/{$sinCuenta}")->assertForbidden();
        $this->como($this->director)->deleteJson("/api/encargados/{$sinCuenta}")->assertStatus(409);
        DB::table('matriculas')->where('id', $matricula)->delete();
        $this->como($this->director)->deleteJson("/api/encargados/{$sinCuenta}")->assertOk();
    }

    public function test_perfil_de_encargado_sirve_para_la_matricula(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $matricula = $this->matriculaDe($estudiante, $this->catalogos);
        $encargado = $this->como($this->a)->postJson('/api/encargados', ['nombre' => 'Tía A', 'parentesco' => 'Tío(a)', 'telefono' => '7000-0001',
            'direccion' => 'Calle 2', 'dui' => '00000001-8'])->json('encargado.id');

        $this->como($this->a)->putJson("/api/matriculas/{$matricula}", ['enfermedad_id' => $this->catalogos['enfermedad'],
            'encargado_id' => $encargado, 'especialidad_id' => $this->catalogos['especialidad']])->assertOk();
        $this->como($this->a)->getJson("/api/estudiantes/{$estudiante->id}")->assertJsonPath('estudiante.matriculas.0.encargado.nombre', 'Tía A');
    }

    // --- Seeder y diagnóstico ---

    public function test_seeder_de_familia_es_reproducible(): void
    {
        $this->seed(UsuarioPruebaSeeder::class);
        $this->seed(FamiliaPruebaSeeder::class);
        $conteo = [Estudiante::count(), Padre::count(), DB::table('parientes')->count()];
        $this->seed(FamiliaPruebaSeeder::class);

        $this->assertSame([2, 2, 4], $conteo);
        $this->assertSame($conteo, [Estudiante::count(), Padre::count(), DB::table('parientes')->count()]);
        $cuenta = User::where('email', 'encargado@siga.test')->first();
        $this->assertSame($cuenta->id, Padre::where('dui', '00000002-6')->value('user_id'));

        Artisan::call('siga:diagnostico-familias');
        $this->assertStringContainsString('Los datos familiares cumplen las reglas de M5.', Artisan::output());
    }

    public function test_diagnostico_detecta_datos_que_no_cumplen(): void
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito'], ['nie' => 'AB-1']);
        $uno = $this->padreDe($estudiante, '00000003-4', 'Madre');
        $dos = Padre::create(['nombre' => 'Repetida', 'dui' => '000000034']);
        DB::table('parientes')->insert(['padre_id' => $dos->id, 'estudiante_id' => $estudiante->id, 'parentesco' => 'Madre']);

        $diagnostico = app(\App\Services\DiagnosticoFamiliasService::class)->ejecutar();

        $this->assertCount(1, $diagnostico['padres_dui_repetido']);
        $this->assertCount(1, $diagnostico['parentesco_repetido']);
        $this->assertCount(1, $diagnostico['nie_no_valido']);
        $this->assertSame(Padre::count(), 2); // solo lee
        $this->assertNotNull($uno->fresh());
    }
}
