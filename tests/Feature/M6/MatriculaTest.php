<?php

namespace Tests\Feature\M6;

use App\Models\AnioLectivo;
use App\Models\PlanAsignatura;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\Encargado;
use App\Models\Estudiante;
use App\Models\Grado;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\PlanEstudio;
use App\Models\Seccion;
use App\Models\User;
use Database\Seeders\AsignacionPruebaSeeder;
use Database\Seeders\FamiliaPruebaSeeder;
use Database\Seeders\GeografiaElSalvadorSeeder;
use Database\Seeders\MatriculaPruebaSeeder;
use Database\Seeders\ProfesorPruebaSeeder;
use Database\Seeders\SeccionPruebaSeeder;
use Database\Seeders\UsuarioPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\M1\DatosM1;
use Tests\Concerns\HoyFijo;
use Tests\TestCase;

/**
 * M6.1: reglas y API de matrícula (D-M1 a D-M9 con los ajustes de Josmy del
 * 13/10/2026). Las reglas se comprueban al crear, editar y aprobar.
 */
class MatriculaTest extends TestCase
{
    use DatosM1, RefreshDatabase, HoyFijo;

    private User $director;
    private User $a;
    private User $b;
    private array $catalogos;
    private AnioLectivo $anio;
    private Grado $primero;
    private Grado $segundo;
    private int $profesor;
    private Seccion $primeroA;
    private Seccion $segundoA;
    private Padre $padreA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('matriculas');
        $this->sembrarRoles();

        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->a = $this->usuario(User::ROL_ENCARGADO);
        $this->b = $this->usuario(User::ROL_ENCARGADO);
        $this->catalogos = $this->catalogosBasicos();
        $this->anio = $this->anio('2027', AnioLectivo::ACTIVO);
        $this->primero = Grado::create(['nombre' => 'Primer grado']);
        $this->segundo = Grado::create(['nombre' => 'Segundo grado']);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $this->profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000003-4', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
        $this->primeroA = $this->seccion($this->primero);
        $this->segundoA = $this->seccion($this->segundo);
        $this->plan($this->primero);
        $this->plan($this->segundo);

        // La cuenta A es el padre de sus estudiantes (responsable sin encargado_id, D-M1)
        $this->padreA = Padre::create(['nombre' => 'Padre A', 'dui' => '00000001-8', 'user_id' => $this->a->id]);
    }

    // ---------------------------------------------------------------- datos

    private function anio(string $nombre, string $estado = AnioLectivo::INACTIVO): AnioLectivo
    {
        $anio = AnioLectivo::create(['anio' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30",
            'inicio_matricula' => ($nombre - 2).'-01-01', 'fin_matricula' => "{$nombre}-11-30"]);
        $anio->forceFill(['estado' => $estado])->save();

        return $anio;
    }

    private function seccion(Grado $grado, string $nombre = 'A', int $capacidad = 30, ?AnioLectivo $anio = null): Seccion
    {
        return Seccion::create([
            'nombre' => $nombre, 'capacidad' => $capacidad, 'turno' => 'matutino', 'anio_lectivo_id' => ($anio ?? $this->anio)->id,
            'grado_id' => $grado->id, 'profesor_id' => $this->profesor,
        ]);
    }

    private function plan(Grado $grado, ?int $especialidadId = null, string $nombre = 'Plan', bool $vigente = true): PlanEstudio
    {
        return PlanEstudio::create([
            'nombre' => "{$nombre} {$grado->id}", 'especialidad_id' => $especialidadId, 'estado' => $vigente,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2030-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
    }

    /** Asigna una materia del plan a la sección: el plan queda definido (M4). */
    private function asignarPlan(Seccion $seccion, PlanEstudio $plan): void
    {
        $materia = PlanAsignatura::enlazar($plan->id, 'Matemática '.$plan->id);
        Asignacion::create(['profesor_id' => $this->profesor, 'plan_asignatura_id' => $materia->id, 'seccion_id' => $seccion->id]);
    }

    private function hijoDeA(array $atributos = []): Estudiante
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito'], $atributos);
        $estudiante->padres()->attach($this->padreA->id, ['parentesco' => 'Padre']);

        return $estudiante;
    }

    /** El encargado solicita con grado y turno (sin sección); la dirección puede indicarla (por defecto Primer grado A). */
    private function solicitar(Estudiante $estudiante, array $datos = [], ?User $cuenta = null)
    {
        $cuenta ??= $this->a;
        $director = $cuenta->is($this->director);
        // El encargado indica grado y turno (por defecto, el grado que permite la promoción, en la mañana)
        $grados = app(\App\Services\PromocionService::class)->opciones($estudiante, $this->anio)['grados'];
        $seccion = $director ? ['seccion_id' => $this->primeroA->id]
            : ['grado_id' => $grados[0] ?? $this->primero->id, 'turno' => 'matutino'];
        // El encargado envía el NIE en Reingreso o con traslado; en Nuevo ingreso sin traslado lo agrega la dirección
        $conNie = $director || ($datos['ingreso'] ?? 'Nuevo ingreso') === 'Reingreso' || ! empty($datos['es_trasladado']);
        $nie = $conNie && ! array_key_exists('nie', $datos) ? ['nie' => $estudiante->nie] : [];

        return $this->como($cuenta)->post('/api/matriculas', $datos + $seccion + $nie + [
            'ingreso' => 'Nuevo ingreso',
            'estudiante_id' => $estudiante->id,
            'foto' => UploadedFile::fake()->image('foto.jpg'),
            'documento' => UploadedFile::fake()->create('partida.pdf', 20, 'application/pdf'),
            'es_trasladado' => 0,
        ]);
    }

    private function aprobar(int $id, array $datos = [], ?User $cuenta = null)
    {
        return $this->como($cuenta ?? $this->director)->putJson("/api/matriculas/{$id}/estado", $datos + ['estado' => 'aprobado']);
    }

    /** Matrícula aprobada de un año anterior (cerrado) con su nota anual. */
    private function historial(Estudiante $estudiante, Grado $grado, ?string $nota = 'Aprobada', ?int $especialidadId = null): int
    {
        $anterior = AnioLectivo::where('anio', 2026)->first() ?? $this->anio('2026', AnioLectivo::CIERRE);
        $seccion = Seccion::where('anio_lectivo_id', $anterior->id)->where('grado_id', $grado->id)->first()
            ?? $this->seccion($grado, 'A', 30, $anterior);
        $id = $this->matriculaDe($estudiante, $this->catalogos, [
            'estado' => 'aprobado', 'anio_lectivo_id' => $anterior->id, 'seccion_id' => $seccion->id,
            'especialidad_id' => $especialidadId, 'enfermedad_id' => null, 'foto' => 'fotos/anterior.jpg',
        ]);
        if ($nota) {
            DB::table('notas_anuales')->insert(['matricula_id' => $id, 'promedio_anual' => $nota === 'Aprobada' ? 8.5 : 5.0, 'estado' => $nota]);
        }

        return $id;
    }

    // ---------------------------------------------------------------- crear

    public function test_crear_sin_seccion_con_documentacion_y_responsable(): void
    {
        $hijo = $this->hijoDeA();

        // El encargado no elige la sección (la asigna la dirección); documentación si proviene de otro centro escolar
        $this->solicitar($hijo, ['seccion_id' => $this->primeroA->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección la asigna la dirección al revisar la matrícula.');
        $this->solicitar($hijo, ['documento' => null, 'es_trasladado' => 1, 'nie' => '4444444'])->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'La documentación es obligatoria si el estudiante proviene de otro centro escolar.');

        // Grado y turno son obligatorios; el turno debe tener secciones con cupo de ese grado
        $this->solicitar($hijo, ['grado_id' => null, 'turno' => null])->assertStatus(422)->assertJsonValidationErrors(['grado_id', 'turno']);
        $this->solicitar($hijo, ['grado_id' => $this->primero->id, 'turno' => 'vespertino'])->assertStatus(422)
            ->assertJsonPath('errors.turno.0', 'No hay secciones con cupo de este grado en el turno de la tarde para 2027.');

        // Sin enfermedad ni especialidad (D-M2, D-M8): queda pendiente en una sección del grado y turno
        // pedidos (la dirección la confirma o cambia al aprobar), y los archivos van al disco privado
        $respuesta = $this->solicitar($hijo)->assertCreated()
            ->assertJsonPath('matricula.estado', 'pendiente')
            ->assertJsonPath('matricula.seccion_id', $this->primeroA->id)
            ->assertJsonPath('matricula.especialidad_id', null)
            ->assertJsonPath('matricula.enfermedad_id', null)
            ->assertJsonMissingPath('matricula.foto');
        $fila = Matricula::find($respuesta->json('matricula.id'));
        $this->assertStringStartsWith('fotos/', $fila->foto);
        $this->assertStringStartsWith('documentos/', $fila->documento);
        Storage::disk('matriculas')->assertExists([$fila->foto, $fila->documento]);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // El responsable identificado es el padre titular de la cuenta, aunque también esté vinculada la madre
        $madre = Padre::create(['nombre' => 'Madre A', 'dui' => '00000005-0']);
        $hijo->padres()->attach($madre->id, ['parentesco' => 'Madre']);
        $this->como($this->a)->getJson("/api/matriculas/{$fila->id}")->assertOk()
            ->assertJsonPath('responsable.tipo', 'padre')
            ->assertJsonPath('responsable.id', $this->padreA->id)
            ->assertJsonPath('responsable.parentesco', 'Padre')
            ->assertJsonPath('archivos.foto', true);
    }

    public function test_responsable_padre_o_encargado_de_la_cuenta(): void
    {
        $encargado = fn (string $nombre) => ['nombre' => $nombre, 'parentesco' => 'Tío(a)', 'telefono' => '7000-0001',
            'dui' => \Database\Factories\UserFactory::duiValido(), 'direccion' => 'Calle 2'];

        // La cuenta A es el padre responsable: no registra un encargado adicional ni lo indica en la matrícula
        $this->como($this->a)->postJson('/api/encargados', $encargado('Tía A'))->assertStatus(409)
            ->assertJsonPath('message', 'Usted está registrado como padre o madre: es el responsable de sus estudiantes y no se registran encargados adicionales.');
        $this->assertSame(0, Encargado::count());
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$this->hijoDeA()->id}/opciones")->assertOk()
            ->assertJsonPath('responsable.tipo', 'padre')->assertJsonPath('responsable.id', $this->padreA->id);
        // Si la cuenta es padre pero no está vinculada a ese estudiante, tampoco hay responsable
        $sinVinculo = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->solicitar($sinVinculo)->assertStatus(422)->assertJsonValidationErrors('encargado_id');

        // Cuenta C, que no es el padre: debe registrar su perfil de encargado (con su user_id) antes de matricular
        $c = $this->usuario(User::ROL_ENCARGADO);
        $sobrino = $this->estudianteDe($c, $this->catalogos['distrito']);
        $this->como($c)->getJson("/api/matriculas/estudiantes/{$sobrino->id}/opciones")->assertOk()
            ->assertJsonPath('responsable.tipo', null);
        $this->solicitar($sobrino, [], $c)->assertStatus(422)
            ->assertJsonPath('errors.encargado_id.0', 'Falta el responsable: la cuenta no está vinculada como padre o madre del estudiante, así que debe registrar su perfil de encargado (Mi perfil) antes de matricular.');
        $perfil = $this->como($c)->postJson('/api/encargados', $encargado('Tío C'))->assertCreated()->json('encargado');
        $this->assertSame($c->id, Encargado::find($perfil['id'])->user_id);
        // Ya con perfil de encargado no puede registrarse también como padre responsable
        $this->como($c)->postJson('/api/padres', ['nombre' => 'Tío C', 'dui' => \Database\Factories\UserFactory::duiValido(), 'es_encargado' => true])
            ->assertStatus(409)->assertJsonPath('message', 'Su cuenta ya tiene un perfil de encargado: el responsable de sus estudiantes es ese perfil.');
        // El perfil se usa como responsable aunque no se envíe encargado_id
        $id = $this->solicitar($sobrino, [], $c)->assertCreated()->assertJsonPath('matricula.encargado_id', $perfil['id'])->json('matricula.id');
        $this->como($c)->getJson("/api/matriculas/{$id}")->assertJsonPath('responsable.tipo', 'encargado')->assertJsonPath('responsable.nombre', 'Tío C');
    }

    public function test_responsable_ajeno_o_estudiante_ajeno_se_rechaza(): void
    {
        $hijo = $this->hijoDeA();
        $deB = Encargado::create(['nombre' => 'Enc B', 'parentesco' => 'Tío(a)', 'telefono' => '7000-0002', 'dui' => '00000006-9', 'direccion' => 'X', 'user_id' => $this->b->id]);
        $sinCuenta = Encargado::create(['nombre' => 'Abuela', 'parentesco' => 'Abuelo(a)', 'telefono' => '7000-0003', 'dui' => '00000007-7', 'direccion' => 'X']);

        $this->solicitar($hijo, ['encargado_id' => $deB->id])->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        // Un encargado sin cuenta no es responsable, ni siquiera indicado por la dirección
        $this->solicitar($hijo, ['encargado_id' => $sinCuenta->id])->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        $this->solicitar($hijo, ['encargado_id' => $sinCuenta->id], $this->director)->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        $this->solicitar($hijo, [], $this->director)->assertCreated()->assertJsonPath('matricula.encargado_id', null);

        $deCuentaB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $this->solicitar($deCuentaB)->assertStatus(422)->assertJsonValidationErrors('estudiante_id');
        $this->assertSame(1, Matricula::count());
    }

    public function test_una_vigente_por_anio_y_cupo_reservado(): void
    {
        $chica = $this->seccion($this->primero, 'B', 1);
        $uno = $this->hijoDeA();
        $dos = $this->hijoDeA();

        $id = $this->solicitar($uno, ['seccion_id' => $chica->id], $this->director)->assertCreated()->json('matricula.id');
        // La misma persona no tiene dos matrículas pendientes o aprobadas en el año
        $this->solicitar($uno)->assertStatus(422)->assertJsonValidationErrors('estudiante_id');
        // La pendiente con sección (asignada por la dirección) reserva el cupo
        $this->solicitar($dos, ['seccion_id' => $chica->id], $this->director)->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección Primer grado B no tiene cupos disponibles (1 de 1).');

        // Rechazada libera el cupo y permite una nueva solicitud
        $this->como($this->director)->putJson("/api/matriculas/{$id}/estado", ['estado' => 'rechazado'])->assertOk();
        $this->solicitar($dos, ['seccion_id' => $chica->id], $this->director)->assertCreated();
        $this->solicitar($uno)->assertCreated();

        // Sección de otro año lectivo
        $otro = $this->seccion($this->primero, 'A', 30, $this->anio('2028'));
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $otro->id], $this->director)->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección no pertenece al año lectivo de la matrícula.');
    }

    public function test_especialidad_se_valida_contra_el_plan_de_la_seccion(): void
    {
        $general = $this->catalogos['especialidad'];
        $contador = DB::table('especialidades')->insertGetId(['nombre' => 'Contador']);
        $bachillerato = Grado::create(['nombre' => 'Primer año de bachillerato']);
        $seccion = $this->seccion($bachillerato);

        // Sección sin materias asignadas: vale la especialidad que elige el encargado
        $planGeneral = $this->plan($bachillerato, $general, 'General');
        $pendiente = $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => $contador], $this->director)->assertCreated()
            ->assertJsonPath('matricula.especialidad_id', $contador)->json('matricula.id');
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => 9999], $this->director)->assertStatus(422)
            ->assertJsonValidationErrors('especialidad_id');

        // Con el plan de la sección definido (General), la especialidad Contador ya no es compatible
        $this->asignarPlan($seccion, $planGeneral);
        $this->aprobar($pendiente)->assertStatus(422)
            ->assertJsonPath('errors.especialidad_id.0', "La especialidad no coincide con el plan de la sección Primer año de bachillerato A ({$planGeneral->nombre}).");
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => $contador], $this->director)->assertStatus(422)
            ->assertJsonValidationErrors('especialidad_id');
        // Sin indicarla, se toma la del plan de la sección
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id], $this->director)->assertCreated()->assertJsonPath('matricula.especialidad_id', $general);

        // Parvularia y Básica sin especialidad (D-M2): el plan sin especialidad sirve
        $this->solicitar($this->hijoDeA(), [], $this->director)->assertCreated()->assertJsonPath('matricula.especialidad_id', null);
        // El encargado puede indicar la especialidad sin sección; se revisa contra el plan al asignarla
        $sinSeccion = $this->solicitar($this->hijoDeA(), ['especialidad_id' => $contador])->assertCreated()
            ->assertJsonPath('matricula.especialidad_id', $contador)->json('matricula.id');
        $this->aprobar($sinSeccion, ['seccion_id' => $seccion->id])->assertStatus(422)->assertJsonValidationErrors('especialidad_id');
    }

    public function test_documentacion_segun_traslado_y_tipo_de_ingreso(): void
    {
        // Nuevo ingreso sin traslado: la documentación es opcional
        $local = $this->hijoDeA(['nie' => null]);
        $id = $this->solicitar($local, ['documento' => null])->assertCreated()
            ->assertJsonPath('matricula.es_trasladado', false)->json('matricula.id');
        $this->assertNull(Matricula::find($id)->documento);

        // Si al editar pasa a "proviene de otro centro escolar", debe adjuntar la documentación
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['es_trasladado' => 1, 'nie' => '3333333'])->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'La documentación es obligatoria si el estudiante proviene de otro centro escolar.');
        $this->como($this->a)->post("/api/matriculas/{$id}", [
            '_method' => 'PUT', 'es_trasladado' => 1, 'nie' => '3333333',
            'documento' => UploadedFile::fake()->create('documentacion.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('matricula.es_trasladado', true);
        $this->assertStringStartsWith('documentos/', Matricula::find($id)->documento);
        // Ya tiene documentación: puede editar otros campos sin volver a subirla
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['es_trasladado' => 1])->assertOk();

        // Reingreso: no se pregunta si proviene de otro centro escolar y queda en falso
        $reingreso = $this->hijoDeA();
        $this->historial($reingreso, $this->primero, 'Aprobada');
        $this->solicitar($reingreso, ['ingreso' => 'Reingreso', 'documento' => null, 'es_trasladado' => 1])->assertStatus(422)
            ->assertJsonPath('errors.es_trasladado.0', 'En Reingreso no se indica si proviene de otro centro escolar.');
        $otro = $this->solicitar($reingreso, ['ingreso' => 'Reingreso', 'documento' => null, 'es_trasladado' => null])->assertCreated()
            ->assertJsonPath('matricula.es_trasladado', false)->json('matricula.id');
        $this->como($this->a)->putJson("/api/matriculas/{$otro}", ['es_trasladado' => 1])->assertStatus(422)
            ->assertJsonValidationErrors('es_trasladado');
    }

    // ------------------------------------------------------------ promoción

    public function test_promocion_reingreso_repite_o_bloquea(): void
    {
        // Con historial en SIGA no puede pasar por Nuevo ingreso (D-M5)
        $aprobo = $this->hijoDeA();
        $this->historial($aprobo, $this->primero, 'Aprobada');
        $this->solicitar($aprobo)->assertStatus(422)
            ->assertJsonPath('errors.ingreso.0', 'El estudiante tiene matrículas aprobadas en SIGA: corresponde Reingreso, con validación de promoción.');
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$aprobo->id}/opciones")->assertOk()
            ->assertJsonPath('ingreso', 'Reingreso')->assertJsonPath('grados.0.id', $this->segundo->id)->assertJsonPath('permitido', true);
        // Aprobó su grado anterior: el encargado solicita y la dirección solo lo ubica en el grado siguiente
        $id = $this->solicitar($aprobo, ['ingreso' => 'Reingreso', 'documento' => null])->assertCreated()->json('matricula.id');
        $this->aprobar($id, ['seccion_id' => $this->primeroA->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'Aprobó Primer grado en 2026: corresponde Segundo grado. La sección elegida es de otro grado.');
        $this->aprobar($id, ['seccion_id' => $this->segundoA->id])->assertOk();

        // Reprobó: no pasa al grado siguiente, repite el mismo
        $reprobo = $this->hijoDeA();
        $this->historial($reprobo, $this->primero, 'Reprobada');
        $this->solicitar($reprobo, ['ingreso' => 'Reingreso', 'seccion_id' => $this->segundoA->id], $this->director)->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'No aprobó Primer grado en 2026: debe repetir el grado. La sección elegida es de otro grado.');
        $id = $this->solicitar($reprobo, ['ingreso' => 'Reingreso'])->assertCreated()->json('matricula.id');
        $this->como($this->director)->getJson("/api/matriculas/{$id}/secciones")->assertOk()
            ->assertJsonCount(1, 'grados')->assertJsonPath('grados.0.id', $this->primero->id);
        $this->aprobar($id, ['seccion_id' => $this->segundoA->id])->assertStatus(422);
        $this->aprobar($id, ['seccion_id' => $this->primeroA->id])->assertOk();

        // Sin nota anual: no se sabe si aprobó, queda bloqueado con aviso
        $sinNota = $this->hijoDeA();
        $this->historial($sinNota, $this->primero, null);
        $this->solicitar($sinNota, ['ingreso' => 'Reingreso'])->assertStatus(422)
            ->assertJsonPath('errors.estudiante_id.0', 'La matrícula de 2026 (Primer grado) aún no tiene nota anual registrada; sin ella no se puede validar la promoción.');
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$sinNota->id}/opciones")->assertOk()->assertJsonPath('permitido', false);

        // Sin historial: Reingreso no corresponde
        $nuevo = $this->hijoDeA();
        $this->solicitar($nuevo, ['ingreso' => 'Reingreso'])->assertStatus(422)->assertJsonValidationErrors('ingreso');
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$nuevo->id}/opciones")->assertOk()
            ->assertJsonPath('ingreso', 'Nuevo ingreso')->assertJsonPath('especialidad_libre', true);

        // Un estudiante ajeno: no encontrado
        $this->como($this->b)->getJson("/api/matriculas/estudiantes/{$nuevo->id}/opciones")->assertNotFound();
    }

    public function test_reingreso_conserva_la_especialidad_y_no_salta_grados(): void
    {
        $contador = DB::table('especialidades')->insertGetId(['nombre' => 'Contador']);
        $general = $this->catalogos['especialidad'];
        $primerAnio = Grado::create(['nombre' => 'Primer año de bachillerato']);
        $segundoAnio = Grado::create(['nombre' => 'Segundo año de bachillerato']);
        $this->plan($segundoAnio, $contador, 'Contador');
        $this->plan($segundoAnio, $general, 'General');
        $seccion = $this->seccion($segundoAnio);

        $estudiante = $this->hijoDeA();
        $this->historial($estudiante, $primerAnio, 'Aprobada', $contador);
        $reingreso = ['ingreso' => 'Reingreso', 'documento' => null];
        $this->solicitar($estudiante, $reingreso + ['especialidad_id' => $general])->assertStatus(422)
            ->assertJsonPath('errors.especialidad_id.0', 'En reingreso se conserva la especialidad del año anterior.');
        $this->solicitar($estudiante, $reingreso)->assertCreated()->assertJsonPath('matricula.especialidad_id', $contador);

        // Aprobó Segundo año de bachillerato: el tercero no existe en el catálogo (Bachillerato General termina en 2.º)
        $egresa = $this->hijoDeA();
        $this->historial($egresa, $segundoAnio, 'Aprobada', $general);
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$egresa->id}/opciones")->assertOk()
            ->assertJsonPath('permitido', false)->assertJsonPath('mensaje', "{$egresa->nombre} aprobó Segundo año de bachillerato, el último grado que ofrece la institución.");
    }

    // ---------------------------------------------------- aprobar y rechazar

    public function test_aprobar_confirma_seccion_y_cupo(): void
    {
        $hijo = $this->hijoDeA();
        $id = $this->solicitar($hijo)->assertCreated()->json('matricula.id');

        // Solo la dirección aprueba; sin sección indicada se confirma la del grado y turno pedidos
        $this->aprobar($id, ['seccion_id' => $this->primeroA->id], $this->a)->assertForbidden();
        $sinSeccion = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'especialidad_id' => null]);
        $this->aprobar($sinSeccion)->assertStatus(422)->assertJsonPath('errors.seccion_id.0', 'Asigne una sección antes de aprobar la matrícula.');
        $this->aprobar($id)->assertOk()->assertJsonPath('matricula.estado', 'aprobado')
            ->assertJsonPath('matricula.seccion_id', $this->primeroA->id);
        $this->assertSame('activo', $hijo->fresh()->estado);
        $this->aprobar($id)->assertStatus(409);
        // Los avisos no están en el diagrama
        $this->como($this->a)->getJson('/api/notificaciones')->assertNotFound();
    }

    public function test_aprobar_vuelve_a_comprobar_el_cupo_y_la_seccion(): void
    {
        $chica = $this->seccion($this->primero, 'B', 1);
        // Datos anteriores a M6.1: dos pendientes en una sección de un cupo y una sin sección
        $uno = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'seccion_id' => $chica->id, 'especialidad_id' => null]);
        $dos = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'seccion_id' => $chica->id, 'especialidad_id' => null]);
        $sinSeccion = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'especialidad_id' => null]);

        $this->aprobar($uno)->assertOk();
        $this->aprobar($dos)->assertStatus(422)->assertJsonPath('errors.seccion_id.0', 'La sección Primer grado B no tiene cupos disponibles (1 de 1).');
        // La dirección confirma otra sección al aprobar
        $this->aprobar($dos, ['seccion_id' => $this->primeroA->id])->assertOk()->assertJsonPath('matricula.seccion_id', $this->primeroA->id);

        $this->aprobar($sinSeccion)->assertStatus(422)->assertJsonPath('errors.seccion_id.0', 'Asigne una sección antes de aprobar la matrícula.');
        $deOtroAnio = $this->seccion($this->primero, 'A', 30, $this->anio('2028'));
        $this->aprobar($sinSeccion, ['seccion_id' => $deOtroAnio->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección no pertenece al año lectivo de la matrícula.');
        $this->aprobar($sinSeccion, ['seccion_id' => $this->primeroA->id])->assertOk();

        // Año cerrado: no se aprueba
        $cerrada = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'seccion_id' => $this->primeroA->id, 'especialidad_id' => null]);
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->aprobar($cerrada)->assertStatus(409);
    }

    public function test_rechazar_matricula(): void
    {
        $id = $this->solicitar($this->hijoDeA())->json('matricula.id');
        $rechazar = fn (array $datos) => $this->como($this->director)->putJson("/api/matriculas/{$id}/estado", $datos);

        $rechazar(['estado' => 'pendiente'])->assertStatus(422)->assertJsonValidationErrors('estado');
        $this->como($this->a)->putJson("/api/matriculas/{$id}/estado", ['estado' => 'rechazado'])->assertForbidden();
        $rechazar(['estado' => 'rechazado'])->assertOk()->assertJsonPath('matricula.estado', 'rechazado');
        $rechazar(['estado' => 'aprobado'])->assertStatus(409);

        $this->como($this->a)->getJson("/api/matriculas/{$id}")->assertJsonMissingPath('motivo_rechazo');
        $this->como($this->a)->getJson('/api/matriculas?estado=rechazado')->assertJsonCount(1, 'matriculas')
            ->assertJsonPath('resumen.rechazado', 1)->assertJsonPath('resumen.pendiente', 0);
    }

    // ----------------------------------------------------- editar y cancelar

    public function test_encargado_edita_y_cancela_solo_pendientes(): void
    {
        $hijo = $this->hijoDeA();
        $id = $this->solicitar($hijo)->json('matricula.id');
        $anterior = Matricula::find($id)->foto;
        $d1 = DB::table('discapacidades')->insertGetId(['nombre' => 'Visual']);

        $this->como($this->a)->post("/api/matriculas/{$id}", [
            '_method' => 'PUT', 'enfermedad_id' => $this->catalogos['enfermedad'], 'discapacidades' => [$d1],
            'foto' => UploadedFile::fake()->image('nueva.png'),
        ])->assertOk()->assertJsonPath('matricula.enfermedad_id', $this->catalogos['enfermedad'])
            ->assertJsonPath('matricula.discapacidades.0.id', $d1);
        $nueva = Matricula::find($id)->foto;
        $this->assertNotSame($anterior, $nueva);
        Storage::disk('matriculas')->assertMissing($anterior);
        Storage::disk('matriculas')->assertExists($nueva);

        // La sección solo la asigna la dirección (revisa cupo y promoción)
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['seccion_id' => $this->segundoA->id])->assertStatus(422)
            ->assertJsonValidationErrors('seccion_id');
        $this->como($this->director)->putJson("/api/matriculas/{$id}", ['seccion_id' => $this->segundoA->id])->assertOk()
            ->assertJsonPath('matricula.seccion_id', $this->segundoA->id);
        $this->como($this->b)->putJson("/api/matriculas/{$id}", ['enfermedad_id' => null])->assertNotFound();

        // Aprobada: el encargado ya no edita ni cancela (D-M7)
        $this->aprobar($id)->assertOk();
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['enfermedad_id' => null])->assertStatus(409);
        $this->como($this->a)->deleteJson("/api/matriculas/{$id}")->assertStatus(409);

        // Cancelar una pendiente la borra con sus archivos y su expediente
        $otro = $this->solicitar($this->hijoDeA(), ['discapacidades' => [$d1]])->json('matricula.id');
        $archivos = [Matricula::find($otro)->foto, Matricula::find($otro)->documento];
        $this->como($this->b)->deleteJson("/api/matriculas/{$otro}")->assertNotFound();
        $this->como($this->a)->deleteJson("/api/matriculas/{$otro}")->assertOk();
        $this->assertNull(Matricula::find($otro));
        $this->assertSame(0, DB::table('expedientes')->where('matricula_id', $otro)->count());
        Storage::disk('matriculas')->assertMissing($archivos);
    }

    public function test_discapacidades_se_guardan_en_expedientes(): void
    {
        $d1 = DB::table('discapacidades')->insertGetId(['nombre' => 'Visual']);
        $d2 = DB::table('discapacidades')->insertGetId(['nombre' => 'Auditiva']);

        $this->solicitar($this->hijoDeA(), ['discapacidades' => [$d1, 999]])->assertStatus(422)->assertJsonValidationErrors('discapacidades.1');
        $this->solicitar($this->hijoDeA(), ['discapacidades' => [$d1, $d1]])->assertStatus(422)->assertJsonValidationErrors('discapacidades.0');
        $id = $this->solicitar($this->hijoDeA(), ['discapacidades' => [$d1, $d2]])->assertCreated()
            ->assertJsonCount(2, 'matricula.discapacidades')->json('matricula.id');

        $this->assertEqualsCanonicalizing([$d1, $d2], DB::table('expedientes')->where('matricula_id', $id)->pluck('discapacidad_id')->map(fn ($v) => (int) $v)->all());
        $this->como($this->director)->getJson("/api/matriculas/{$id}")->assertJsonCount(2, 'discapacidades');

        // Quitar todas
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['discapacidades' => []])->assertOk();
        $this->assertSame(0, DB::table('expedientes')->where('matricula_id', $id)->count());
    }

    // ------------------------------------------------- archivos y sección

    public function test_foto_y_documento_solo_por_la_api(): void
    {
        $id = $this->solicitar($this->hijoDeA())->json('matricula.id');

        $this->como($this->a)->get("/api/matriculas/{$id}/archivos/foto")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->como($this->a)->get("/api/matriculas/{$id}/archivos/documento")->assertOk();
        $this->como($this->director)->get("/api/matriculas/{$id}/archivos/foto")->assertOk();
        $this->como($this->b)->get("/api/matriculas/{$id}/archivos/foto")->assertNotFound();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->unsetToken();
        $this->getJson("/api/matriculas/{$id}/archivos/foto")->assertUnauthorized();
        $this->como($this->a)->get("/api/matriculas/{$id}/archivos/otro")->assertNotFound();

        // Las rutas internas no aparecen en el listado ni en el detalle
        $this->como($this->a)->getJson('/api/matriculas')->assertJsonMissingPath('matriculas.0.foto');
        $this->como($this->a)->getJson("/api/matriculas/{$id}")->assertJsonMissingPath('foto')->assertJsonMissingPath('documento');
    }

    public function test_listado_con_filtros(): void
    {
        $luis = $this->hijoDeA(['nombre' => 'Luis Pérez', 'nie' => '1234567']);
        $this->solicitar($luis);
        $this->solicitar($this->hijoDeA(['nombre' => 'Ana Ruiz']), ['seccion_id' => $this->segundoA->id], $this->director);

        $this->como($this->director)->getJson('/api/matriculas?q=Luis')->assertJsonCount(1, 'matriculas')->assertJsonPath('matriculas.0.estudiante.nombre', 'Luis Pérez');
        $this->como($this->director)->getJson('/api/matriculas?q=1234')->assertJsonCount(1, 'matriculas');
        $this->como($this->director)->getJson("/api/matriculas?grado_id={$this->segundo->id}")->assertJsonCount(1, 'matriculas');
        $this->como($this->director)->getJson('/api/matriculas?estado=pendiente')->assertJsonCount(2, 'matriculas')->assertJsonPath('resumen.pendiente', 2);
        $this->como($this->director)->getJson('/api/matriculas?estado=otro')->assertStatus(422);
        $this->como($this->b)->getJson('/api/matriculas')->assertJsonCount(0, 'matriculas');
    }

    // ------------------------------------------------------ NIE y secciones

    public function test_nie_segun_tipo_de_ingreso_y_traslado(): void
    {
        $sinNie = $this->hijoDeA(['nie' => null]);
        $otro = $this->hijoDeA(['nie' => '7654321']);
        $trasladado = ['es_trasladado' => 1];

        // Trasladado (o Reingreso): el encargado lo ingresa, obligatorio, solo números y único
        $this->solicitar($sinNie, $trasladado)->assertStatus(422)->assertJsonPath('errors.nie.0', 'El NIE del estudiante es obligatorio.');
        $this->solicitar($sinNie, $trasladado + ['nie' => '12AB'])->assertStatus(422)->assertJsonPath('errors.nie.0', 'El NIE debe tener solo números (hasta 10 dígitos).');
        $this->solicitar($sinNie, $trasladado + ['nie' => '7654321'])->assertStatus(422)->assertJsonPath('errors.nie.0', 'El NIE ya está registrado; contacte a la dirección.');
        $id = $this->solicitar($sinNie, $trasladado + ['nie' => '1234567'])->assertCreated()->json('matricula.id');
        $this->assertSame('1234567', $sinNie->fresh()->nie);
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['nie' => '1234568'])->assertOk();
        $this->assertSame('1234568', $sinNie->fresh()->nie);
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['nie' => $otro->nie])->assertStatus(422)->assertJsonValidationErrors('nie');

        // Nuevo ingreso sin traslado: el encargado no puede ingresarlo
        $nuevo = $this->hijoDeA(['nie' => null]);
        $this->solicitar($nuevo, ['nie' => '5555555'])->assertStatus(422)
            ->assertJsonPath('errors.nie.0', 'En Nuevo ingreso sin traslado el NIE lo registra la dirección al aprobar la matrícula.');
        $pendiente = $this->solicitar($nuevo)->assertCreated()->json('matricula.id');
        $this->assertNull($nuevo->fresh()->nie);
        $this->como($this->a)->putJson("/api/matriculas/{$pendiente}", ['nie' => '5555555'])->assertStatus(422)->assertJsonValidationErrors('nie');

        // La dirección lo agrega al aprobar y se guarda en estudiantes
        $this->aprobar($pendiente, ['seccion_id' => $this->primeroA->id])->assertStatus(422)
            ->assertJsonPath('errors.nie.0', 'Ingrese el NIE del estudiante para aprobar la matrícula.');
        $this->aprobar($pendiente, ['seccion_id' => $this->primeroA->id, 'nie' => $otro->nie])->assertStatus(422)->assertJsonValidationErrors('nie');
        $this->aprobar($pendiente, ['seccion_id' => $this->primeroA->id, 'nie' => '5555555'])->assertOk();
        $this->assertSame('5555555', $nuevo->fresh()->nie);
    }

    public function test_secciones_para_asignar_sin_llenas_ni_turnos_vacios(): void
    {
        $id = $this->solicitar($this->hijoDeA())->assertCreated()->json('matricula.id');
        $vespertina = Seccion::create(['nombre' => 'B', 'capacidad' => 1, 'turno' => 'vespertino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $this->primero->id, 'profesor_id' => $this->profesor]);
        $this->segundoA->update(['capacidad' => 1]);

        // El responsable pidió Primer grado en la mañana: la dirección solo elige la sección
        $this->como($this->director)->getJson("/api/matriculas/{$id}/secciones")->assertOk()
            ->assertJsonCount(1, 'grados')
            ->assertJsonPath('grados.0.id', $this->primero->id)
            ->assertJsonCount(1, 'grados.0.turnos')
            ->assertJsonPath('grados.0.turnos.0.turno', 'matutino');
        // Otra sección del mismo grado y otro turno no se acepta al aprobar
        $this->aprobar($id, ['seccion_id' => $vespertina->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección debe ser de Primer grado, turno matutino: es lo que pidió el responsable.');

        // Solicitud anterior sin grado ni turno: todos los grados con secciones con cupo, cada uno con sus turnos
        $id = $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['estado' => 'pendiente', 'anio_lectivo_id' => $this->anio->id, 'seccion_id' => null, 'especialidad_id' => null]);
        $this->como($this->director)->getJson("/api/matriculas/{$id}/secciones")->assertOk()
            ->assertJsonCount(2, 'grados')
            ->assertJsonPath('grados.0.turnos.0.turno', 'matutino')
            ->assertJsonPath('grados.0.turnos.1.turno', 'vespertino')
            ->assertJsonPath('grados.0.turnos.1.secciones.0.cupos_disponibles', 1);

        // Se llenan la vespertina de Primer grado y la única de Segundo grado: desaparecen ese turno y ese grado
        $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['estado' => 'aprobado', 'anio_lectivo_id' => $this->anio->id, 'seccion_id' => $vespertina->id, 'especialidad_id' => null]);
        $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['estado' => 'aprobado', 'anio_lectivo_id' => $this->anio->id, 'seccion_id' => $this->segundoA->id, 'especialidad_id' => null]);
        $this->como($this->director)->getJson("/api/matriculas/{$id}/secciones")->assertOk()
            ->assertJsonCount(1, 'grados')
            ->assertJsonPath('grados.0.id', $this->primero->id)
            ->assertJsonCount(1, 'grados.0.turnos')
            ->assertJsonPath('grados.0.turnos.0.turno', 'matutino')
            ->assertJsonPath('grados.0.turnos.0.secciones.0.id', $this->primeroA->id);
        $this->aprobar($id, ['seccion_id' => $vespertina->id])->assertStatus(422)->assertJsonValidationErrors('seccion_id');

        // El formulario del encargado muestra por grado solo los turnos con cupo
        $hijo = $this->hijoDeA()->id;
        $grados = collect($this->como($this->a)->getJson("/api/matriculas/estudiantes/{$hijo}/opciones")->assertOk()->json('grados'))->keyBy('id');
        $this->assertSame(['matutino'], $grados[$this->primero->id]['turnos']);
        $this->assertSame('Educación Básica', $grados[$this->primero->id]['nivel']);
        $this->assertSame([], $grados[$this->segundo->id]['turnos']);

        // Solo la dirección consulta
        $this->como($this->a)->getJson("/api/matriculas/{$id}/secciones")->assertForbidden();

        // Revisar: el detalle trae el correo de la cuenta y el lugar de nacimiento
        $this->como($this->director)->getJson("/api/matriculas/{$id}")->assertOk()
            ->assertJsonPath('estudiante.user.email', $this->a->email)
            ->assertJsonStructure(['estudiante' => ['direccion', 'distrito' => ['nombre', 'municipio' => ['nombre', 'departamento' => ['nombre']]]]]);
    }

    // ------------------------------------------------- diagnóstico y seeder

    // Catálogo de salud sembrado; el medicamento escrito queda en la enfermedad «Nombre (medicamento)»
    public function test_catalogo_de_salud_y_medicamento_escrito(): void
    {
        $this->seed(\Database\Seeders\CatalogoSaludSeeder::class);
        $this->seed(\Database\Seeders\CatalogoSaludSeeder::class);
        $this->assertSame(count(\Database\Seeders\CatalogoSaludSeeder::ENFERMEDADES), \App\Models\Enfermedad::whereIn('nombre', \Database\Seeders\CatalogoSaludSeeder::ENFERMEDADES)->count());
        $this->assertSame(count(\Database\Seeders\CatalogoSaludSeeder::DISCAPACIDADES), \App\Models\Discapacidad::whereIn('nombre', \Database\Seeders\CatalogoSaludSeeder::DISCAPACIDADES)->count());
        $asma = \App\Models\Enfermedad::where('nombre', 'Asma')->first();

        $this->solicitar($this->hijoDeA(), ['medicamento' => 'Salbutamol'])->assertStatus(422)->assertJsonValidationErrors('enfermedad_id');

        $id = $this->solicitar($this->hijoDeA(), ['enfermedad_id' => $asma->id, 'medicamento' => ' Salbutamol  inhalado '])->assertCreated()->json('matricula.id');
        $enfermedad = Matricula::find($id)->enfermedad()->first();
        $this->assertSame(['Asma (Salbutamol inhalado)', 'Salbutamol inhalado'], [$enfermedad->nombre, $enfermedad->medicamento]);
        $this->assertSame('', $asma->fresh()->medicamento);

        // El mismo medicamento reutiliza la enfermedad; sin medicamento queda la del catálogo
        $otra = $this->solicitar($this->hijoDeA(), ['enfermedad_id' => $asma->id, 'medicamento' => 'Salbutamol inhalado'])->assertCreated()->json('matricula.enfermedad_id');
        $this->assertSame($enfermedad->id, $otra);
        $this->solicitar($this->hijoDeA(), ['enfermedad_id' => $asma->id])->assertCreated()->assertJsonPath('matricula.enfermedad_id', $asma->id);
    }

    public function test_diagnostico_informa_sin_modificar(): void
    {
        $this->solicitar($this->hijoDeA())->assertCreated();
        Artisan::call('siga:diagnostico-matriculas');
        $this->assertStringContainsString('Las matrículas cumplen las reglas de M6.1.', Artisan::output());

        // Datos anteriores con problemas
        $chica = $this->seccion($this->primero, 'B', 1);
        $sinPadre = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $hijo = $this->hijoDeA();
        $this->matriculaDe($hijo, $this->catalogos, ['estado' => 'aprobado', 'anio_lectivo_id' => $this->anio->id, 'especialidad_id' => null]);
        $this->matriculaDe($hijo, $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'seccion_id' => $chica->id, 'especialidad_id' => null]);
        $this->matriculaDe($sinPadre, $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'seccion_id' => $chica->id, 'especialidad_id' => null]);
        $this->matriculaDe($this->hijoDeA(), $this->catalogos, ['anio_lectivo_id' => $this->anio->id, 'especialidad_id' => null, 'foto' => 'fotos/no-existe.jpg']);
        $antes = DB::table('matriculas')->get()->toArray();

        $diagnostico = app(\App\Services\DiagnosticoMatriculasService::class)->ejecutar();
        $this->assertCount(1, $diagnostico['duplicadas']);
        $this->assertCount(1, $diagnostico['secciones_sobre_cupo']);
        $this->assertCount(1, $diagnostico['aprobadas_sin_seccion']);
        $this->assertCount(1, $diagnostico['sin_responsable']);
        $this->assertCount(3, $diagnostico['archivos_publicos']);
        $this->assertCount(1, $diagnostico['archivos_faltantes']);

        Artisan::call('siga:diagnostico-matriculas');
        $salida = Artisan::output();
        $this->assertStringContainsString('caso(s) para revisar manualmente. No se modificó nada.', $salida);
        $this->assertStringContainsString('Informativo: matrículas pendientes sin sección (la dirección la asigna al aprobar): 1', $salida);
        $this->assertEquals($antes, DB::table('matriculas')->get()->toArray());
    }

    public function test_seeder_de_matriculas_es_reproducible(): void
    {
        // Libera el DUI del padre de prueba; usa el año, las secciones y los planes de esta prueba
        $this->padreA->update(['dui' => \Database\Factories\UserFactory::duiValido()]);
        $this->seed([UsuarioPruebaSeeder::class, ProfesorPruebaSeeder::class, SeccionPruebaSeeder::class, AsignacionPruebaSeeder::class, GeografiaElSalvadorSeeder::class, FamiliaPruebaSeeder::class]);

        $this->seed(MatriculaPruebaSeeder::class);
        $this->seed(MatriculaPruebaSeeder::class);

        $cuenta = User::where('email', 'encargado@siga.test')->first();
        $matriculas = Matricula::whereHas('estudiante', fn ($q) => $q->where('user_id', $cuenta->id))->get();
        $this->assertCount(2, $matriculas);
        $this->assertSame(['pendiente'], $matriculas->pluck('estado')->unique()->values()->all());
        foreach ($matriculas as $m) {
            Storage::disk('matriculas')->assertExists([$m->foto, $m->documento]);
        }
        Artisan::call('siga:diagnostico-matriculas');
        $this->assertStringContainsString('Las matrículas cumplen las reglas de M6.1.', Artisan::output());
    }
}
