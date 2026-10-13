<?php

namespace Tests\Feature\M6;

use App\Models\AnioLectivo;
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
use App\Notifications\MatriculaAprobada;
use App\Notifications\MatriculaRechazada;
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
use Tests\TestCase;

/**
 * M6.1: reglas y API de matrícula (D-M1 a D-M9 con los ajustes de Josmy del
 * 13/10/2026). Las reglas se comprueban al crear, editar y aprobar.
 */
class MatriculaTest extends TestCase
{
    use DatosM1, RefreshDatabase;

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
        $this->a = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $this->b = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000002-6']);
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
        $anio = AnioLectivo::create(['nombre' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30"]);
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
            'nombre' => "{$nombre} {$grado->id}", 'grado_id' => $grado->id, 'especialidad_id' => $especialidadId, 'estado' => $vigente,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2030-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
    }

    /** Asigna una materia del plan a la sección: el plan queda definido (M4). */
    private function asignarPlan(Seccion $seccion, PlanEstudio $plan): void
    {
        $materia = Asignatura::create(['nombre' => 'Matemática '.$plan->id, 'plan_estudio_id' => $plan->id]);
        Asignacion::create(['profesor_id' => $this->profesor, 'asignatura_id' => $materia->id, 'seccion_id' => $seccion->id]);
    }

    private function hijoDeA(array $atributos = []): Estudiante
    {
        $estudiante = $this->estudianteDe($this->a, $this->catalogos['distrito'], $atributos);
        $estudiante->padres()->attach($this->padreA->id, ['parentesco' => 'Padre']);

        return $estudiante;
    }

    private function solicitar(Estudiante $estudiante, array $datos = [], ?User $cuenta = null)
    {
        return $this->como($cuenta ?? $this->a)->post('/api/matriculas', $datos + [
            'ingreso' => 'Nuevo ingreso',
            'estudiante_id' => $estudiante->id,
            'seccion_id' => $this->primeroA->id,
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
        $anterior = AnioLectivo::where('nombre', '2026')->first() ?? $this->anio('2026', AnioLectivo::CIERRE);
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

    public function test_crear_exige_seccion_partida_y_responsable(): void
    {
        $hijo = $this->hijoDeA();

        // Sección obligatoria (D-M4) y partida para Nuevo ingreso (pág. 57)
        $this->solicitar($hijo, ['seccion_id' => null])->assertStatus(422)->assertJsonValidationErrors('seccion_id');
        $this->solicitar($hijo, ['documento' => null])->assertStatus(422)
            ->assertJsonPath('errors.documento.0', 'La partida de nacimiento es obligatoria para Nuevo ingreso.');

        // Sin encargado_id y sin que la cuenta sea padre o madre del estudiante: falta el responsable (D-M1)
        $sinVinculo = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->solicitar($sinVinculo)->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        // Con el perfil de encargado propio sí se admite
        $tia = Encargado::create(['nombre' => 'Tía A', 'parentesco' => 'Tío(a)', 'telefono' => '7000-0001', 'dui' => '00000004-2', 'direccion' => 'X', 'user_id' => $this->a->id]);
        $this->solicitar($sinVinculo, ['encargado_id' => $tia->id])->assertCreated();

        // Sin enfermedad ni especialidad (D-M2, D-M8): queda pendiente y los archivos van al disco privado
        $respuesta = $this->solicitar($hijo)->assertCreated()
            ->assertJsonPath('matricula.estado', 'pendiente')
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

    public function test_responsable_ajeno_o_estudiante_ajeno_se_rechaza(): void
    {
        $hijo = $this->hijoDeA();
        $deB = Encargado::create(['nombre' => 'Enc B', 'parentesco' => 'Tío(a)', 'telefono' => '7000-0002', 'dui' => '00000006-9', 'direccion' => 'X', 'user_id' => $this->b->id]);
        $sinCuenta = Encargado::create(['nombre' => 'Abuela', 'parentesco' => 'Abuelo(a)', 'telefono' => '7000-0003', 'dui' => '00000007-7', 'direccion' => 'X']);

        $this->solicitar($hijo, ['encargado_id' => $deB->id])->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        // Un encargado sin cuenta solo lo indica la dirección
        $this->solicitar($hijo, ['encargado_id' => $sinCuenta->id])->assertStatus(422)->assertJsonValidationErrors('encargado_id');
        $this->solicitar($hijo, ['encargado_id' => $sinCuenta->id], $this->director)->assertCreated()
            ->assertJsonPath('matricula.encargado_id', $sinCuenta->id);

        $deCuentaB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $this->solicitar($deCuentaB)->assertStatus(422)->assertJsonValidationErrors('estudiante_id');
        $this->assertSame(1, Matricula::count());
    }

    public function test_una_vigente_por_anio_y_cupo_reservado(): void
    {
        $chica = $this->seccion($this->primero, 'B', 1);
        $uno = $this->hijoDeA();
        $dos = $this->hijoDeA();

        $id = $this->solicitar($uno, ['seccion_id' => $chica->id])->assertCreated()->json('matricula.id');
        // La misma persona no tiene dos matrículas pendientes o aprobadas en el año
        $this->solicitar($uno)->assertStatus(422)->assertJsonValidationErrors('estudiante_id');
        // La pendiente reserva el cupo
        $this->solicitar($dos, ['seccion_id' => $chica->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección Primer grado B no tiene cupos disponibles (1 de 1).');

        // Rechazada libera el cupo y permite una nueva solicitud
        $this->como($this->director)->putJson("/api/matriculas/{$id}/estado", ['estado' => 'rechazado', 'motivo' => 'Documentos ilegibles'])->assertOk();
        $this->solicitar($dos, ['seccion_id' => $chica->id])->assertCreated();
        $this->solicitar($uno)->assertCreated();

        // Sección de otro año lectivo
        $otro = $this->seccion($this->primero, 'A', 30, $this->anio('2028'));
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $otro->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La sección no pertenece al año lectivo de la matrícula.');
    }

    public function test_especialidad_se_valida_contra_el_plan_de_la_seccion(): void
    {
        $general = $this->catalogos['especialidad'];
        $contador = DB::table('especialidades')->insertGetId(['nombre' => 'Contador']);
        $bachillerato = Grado::create(['nombre' => 'Primer año de bachillerato']);
        $seccion = $this->seccion($bachillerato);

        // Grado sin plan vigente: no se matricula
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'El grado Primer año de bachillerato no tiene un plan de estudio vigente: no se puede matricular.');

        // Dos planes vigentes: hay que indicar la especialidad; una sin plan se rechaza
        $planGeneral = $this->plan($bachillerato, $general, 'General');
        $this->plan($bachillerato, $contador, 'Contador');
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id])->assertStatus(422)
            ->assertJsonPath('errors.especialidad_id.0', 'El grado Primer año de bachillerato tiene varios planes vigentes: indique la especialidad.');
        $otra = DB::table('especialidades')->insertGetId(['nombre' => 'Salud']);
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => $otra])->assertStatus(422)
            ->assertJsonValidationErrors('especialidad_id');
        $pendiente = $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => $contador])->assertCreated()
            ->assertJsonPath('matricula.especialidad_id', $contador)->json('matricula.id');

        // Aprobar con plan ambiguo (sección sin materias asignadas y dos planes): bloqueado
        $this->aprobar($pendiente)->assertStatus(422)->assertJsonValidationErrors('seccion_id');
        $this->assertSame('pendiente', Matricula::find($pendiente)->estado);

        // Con el plan de la sección definido (General), la especialidad Contador ya no es compatible
        $this->asignarPlan($seccion, $planGeneral);
        $this->aprobar($pendiente)->assertStatus(422)
            ->assertJsonPath('errors.especialidad_id.0', "La especialidad no coincide con el plan de la sección Primer año de bachillerato A ({$planGeneral->nombre}).");
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id, 'especialidad_id' => $contador])->assertStatus(422)
            ->assertJsonValidationErrors('especialidad_id');
        // Sin indicarla, se toma la del plan de la sección
        $this->solicitar($this->hijoDeA(), ['seccion_id' => $seccion->id])->assertCreated()->assertJsonPath('matricula.especialidad_id', $general);

        // Parvularia y Básica sin especialidad (D-M2): el plan sin especialidad sirve
        $this->solicitar($this->hijoDeA())->assertCreated()->assertJsonPath('matricula.especialidad_id', null);
    }

    // ------------------------------------------------------------ promoción

    public function test_promocion_reingreso_repite_o_bloquea(): void
    {
        // Con historial en SIGA no puede pasar por Nuevo ingreso (D-M5)
        $aprobo = $this->hijoDeA();
        $this->historial($aprobo, $this->primero, 'Aprobada');
        $this->solicitar($aprobo, ['seccion_id' => $this->segundoA->id])->assertStatus(422)
            ->assertJsonPath('errors.ingreso.0', 'El estudiante tiene matrículas aprobadas en SIGA: corresponde Reingreso, con validación de promoción.');
        $this->solicitar($aprobo, ['ingreso' => 'Reingreso', 'documento' => null])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'Aprobó Primer grado en 2026: corresponde Segundo grado. La sección elegida es de otro grado.');
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$aprobo->id}/opciones")->assertOk()
            ->assertJsonPath('ingreso', 'Reingreso')->assertJsonPath('grados.0.id', $this->segundo->id)->assertJsonPath('permitido', true);
        $this->solicitar($aprobo, ['ingreso' => 'Reingreso', 'seccion_id' => $this->segundoA->id, 'documento' => null])->assertCreated();

        // Reprobó: repite el mismo grado
        $reprobo = $this->hijoDeA();
        $this->historial($reprobo, $this->primero, 'Reprobada');
        $this->solicitar($reprobo, ['ingreso' => 'Reingreso', 'seccion_id' => $this->segundoA->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'No aprobó Primer grado en 2026: debe repetir el grado. La sección elegida es de otro grado.');
        $this->solicitar($reprobo, ['ingreso' => 'Reingreso'])->assertCreated();

        // Sin nota anual (hasta M7): bloqueado con aviso
        $sinNota = $this->hijoDeA();
        $this->historial($sinNota, $this->primero, null);
        $this->solicitar($sinNota, ['ingreso' => 'Reingreso', 'seccion_id' => $this->segundoA->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La matrícula de 2026 (Primer grado) aún no tiene nota anual registrada; sin ella no se puede validar la promoción.');
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
        $reingreso = ['ingreso' => 'Reingreso', 'seccion_id' => $seccion->id, 'documento' => null];
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

    public function test_aprobar_confirma_seccion_cupo_y_notifica(): void
    {
        $hijo = $this->hijoDeA();
        $id = $this->solicitar($hijo)->assertCreated()->json('matricula.id');

        // Solo la dirección aprueba
        $this->aprobar($id, [], $this->a)->assertForbidden();
        $this->aprobar($id)->assertOk()->assertJsonPath('matricula.estado', 'aprobado');
        $this->assertSame('activo', $hijo->fresh()->estado);
        $this->aprobar($id)->assertStatus(409);

        // Aviso a la cuenta del estudiante (D-M6)
        $this->como($this->a)->getJson('/api/notificaciones')->assertOk()
            ->assertJsonPath('no_leidas', 1)
            ->assertJsonPath('notificaciones.0.tipo', 'matricula_aprobada')
            ->assertJsonPath('notificaciones.0.matricula_id', $id);
        $aviso = $this->a->notifications()->first();
        $this->assertSame(MatriculaAprobada::class, $aviso->type);
        $this->como($this->b)->postJson("/api/notificaciones/{$aviso->id}/leida")->assertNotFound();
        $this->como($this->a)->postJson("/api/notificaciones/{$aviso->id}/leida")->assertOk();
        $this->como($this->a)->getJson('/api/notificaciones')->assertJsonPath('no_leidas', 0)->assertJsonPath('notificaciones.0.leida', true);
        $this->como($this->b)->getJson('/api/notificaciones')->assertJsonCount(0, 'notificaciones');
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

    public function test_rechazar_exige_motivo_y_lo_notifica(): void
    {
        $id = $this->solicitar($this->hijoDeA())->json('matricula.id');
        $rechazar = fn (array $datos) => $this->como($this->director)->putJson("/api/matriculas/{$id}/estado", $datos);

        $rechazar(['estado' => 'rechazado'])->assertStatus(422)->assertJsonPath('errors.motivo.0', 'El motivo del rechazo es obligatorio.');
        $rechazar(['estado' => 'rechazado', 'motivo' => 'no'])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $rechazar(['estado' => 'pendiente'])->assertStatus(422)->assertJsonValidationErrors('estado');
        $rechazar(['estado' => 'rechazado', 'motivo' => 'La partida no es legible.'])->assertOk()->assertJsonPath('matricula.estado', 'rechazado');
        $rechazar(['estado' => 'aprobado'])->assertStatus(409);

        $aviso = $this->a->notifications()->first();
        $this->assertSame(MatriculaRechazada::class, $aviso->type);
        $this->assertSame('La partida no es legible.', $aviso->data['motivo']);
        $this->como($this->a)->getJson("/api/matriculas/{$id}")->assertJsonPath('motivo_rechazo', 'La partida no es legible.');
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

        // Cambiar a otra sección del mismo año revisa el cupo; de otro grado, la promoción
        $this->como($this->a)->putJson("/api/matriculas/{$id}", ['seccion_id' => $this->segundoA->id])->assertOk();
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

    public function test_director_cambia_la_seccion_del_mismo_grado(): void
    {
        $id = $this->solicitar($this->hijoDeA())->json('matricula.id');
        $this->aprobar($id)->assertOk();
        $primeroB = $this->seccion($this->primero, 'B');

        $this->como($this->a)->putJson("/api/matriculas/{$id}/seccion", ['seccion_id' => $primeroB->id])->assertForbidden();
        $this->como($this->director)->putJson("/api/matriculas/{$id}/seccion", ['seccion_id' => $this->segundoA->id])->assertStatus(422)
            ->assertJsonPath('errors.seccion_id.0', 'La nueva sección debe ser del mismo grado.');
        $this->como($this->director)->putJson("/api/matriculas/{$id}/seccion", ['seccion_id' => $primeroB->id])->assertOk()
            ->assertJsonPath('matricula.seccion_id', $primeroB->id);
    }

    public function test_listado_con_filtros(): void
    {
        $luis = $this->hijoDeA(['nombre' => 'Luis Pérez', 'nie' => '1234567']);
        $this->solicitar($luis);
        $this->solicitar($this->hijoDeA(['nombre' => 'Ana Ruiz']), ['seccion_id' => $this->segundoA->id]);

        $this->como($this->director)->getJson('/api/matriculas?q=Luis')->assertJsonCount(1, 'matriculas')->assertJsonPath('matriculas.0.estudiante.nombre', 'Luis Pérez');
        $this->como($this->director)->getJson('/api/matriculas?q=1234')->assertJsonCount(1, 'matriculas');
        $this->como($this->director)->getJson("/api/matriculas?grado_id={$this->segundo->id}")->assertJsonCount(1, 'matriculas');
        $this->como($this->director)->getJson('/api/matriculas?estado=pendiente')->assertJsonCount(2, 'matriculas')->assertJsonPath('resumen.pendiente', 2);
        $this->como($this->director)->getJson('/api/matriculas?estado=otro')->assertStatus(422);
        $this->como($this->b)->getJson('/api/matriculas')->assertJsonCount(0, 'matriculas');
    }

    // ------------------------------------------------- diagnóstico y seeder

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
        // Libera los DUI de las cuentas de prueba; usa el año, las secciones y los planes de esta prueba
        $dui = \Database\Factories\UserFactory::duiValido();
        $this->a->update(['dui' => $dui]);
        $this->padreA->update(['dui' => $dui]);
        $this->b->update(['dui' => \Database\Factories\UserFactory::duiValido()]);
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
