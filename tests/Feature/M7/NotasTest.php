<?php

namespace Tests\Feature\M7;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\Calificacion;
use App\Models\Evaluacion;
use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Models\PlanEstudio;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M7 Notas, según el diagrama de clases: Profesor.obtenerEstudiantes(asignacion),
 * Evaluacion (crear, eliminar, validarDisponibilidad) y Calificacion (registrar, actualizar).
 */
class NotasTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private array $catalogos;
    private AnioLectivo $anio;
    private PeriodoEscolar $periodo;
    private User $cuentaDocente;
    private Profesor $docente;
    private Seccion $seccion;
    private Asignacion $asignacion;
    private Asignacion $ajena;
    private array $matriculas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->anio = $this->anio('2026', AnioLectivo::ACTIVO);
        $this->periodo = PeriodoEscolar::create(['nombre_periodo' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $this->anio->id]);
        [$this->cuentaDocente, $this->docente] = $this->nuevoDocente();
        [, $otro] = $this->nuevoDocente();

        $grado = Grado::create(['nombre' => 'Primer grado']);
        $this->seccion = $this->seccion($grado, $this->anio);
        $plan = PlanEstudio::create([
            'nombre' => 'Plan', 'especialidad_id' => null, 'estado' => true,
            'fecha_inicio' => '2026-01-15', 'fecha_fin' => '2030-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
        $matematica = Asignatura::create(['nombre' => 'Matemática', 'plan_estudio_id' => $plan->id]);
        $lenguaje = Asignatura::create(['nombre' => 'Lenguaje', 'plan_estudio_id' => $plan->id]);
        $this->asignacion = Asignacion::create(['profesor_id' => $this->docente->id, 'asignatura_id' => $matematica->id, 'seccion_id' => $this->seccion->id]);
        $this->ajena = Asignacion::create(['profesor_id' => $otro->id, 'asignatura_id' => $lenguaje->id, 'seccion_id' => $this->seccion->id]);

        $encargado = $this->usuario(User::ROL_ENCARGADO);
        foreach (['Zoila Pérez' => 'aprobado', 'Ana López' => 'aprobado', 'Pedro Pendiente' => 'pendiente'] as $nombre => $estado) {
            $estudiante = $this->estudianteDe($encargado, $this->catalogos['distrito'], ['nombre' => $nombre]);
            $this->matriculas[$nombre] = $this->matriculaDe($estudiante, $this->catalogos, [
                'estado' => $estado, 'seccion_id' => $this->seccion->id, 'anio_lectivo_id' => $this->anio->id,
            ]);
        }
    }

    // --- Utilidades ---

    private function anio(string $nombre, string $estado = AnioLectivo::INACTIVO): AnioLectivo
    {
        $anio = AnioLectivo::create(['anio' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30"]);
        $anio->forceFill(['estado' => $estado])->save();

        return $anio;
    }

    private function nuevoDocente(): array
    {
        static $n = 0;
        $n++;
        $user = $this->usuario(User::ROL_DOCENTE);

        return [$user, Profesor::create([
            'nombre' => "Docente {$n}", 'dui' => sprintf('3%07d-0', $n), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => sprintf('PROF-7%02d', $n), 'telefono' => sprintf('7200-%04d', $n), 'direccion' => 'X', 'user_id' => $user->id,
        ])];
    }

    private function seccion(Grado $grado, AnioLectivo $anio): Seccion
    {
        return Seccion::create([
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $anio->id,
            'grado_id' => $grado->id, 'profesor_id' => $this->docente->id,
        ]);
    }

    private function crear(array $datos = [], ?Asignacion $asignacion = null)
    {
        return $this->como($this->cuentaDocente)->postJson('/api/docente/asignaciones/'.($asignacion ?? $this->asignacion)->id.'/evaluaciones', $datos + [
            'nombre' => 'Examen', 'porcentaje' => 30, 'periodo_escolar_id' => $this->periodo->id,
        ]);
    }

    private function guardar(int $evaluacionId, array $notas)
    {
        return $this->como($this->cuentaDocente)->putJson("/api/docente/evaluaciones/{$evaluacionId}/calificaciones", ['notas' => $notas]);
    }

    // --- Pruebas ---

    public function test_obtener_estudiantes_solo_aprobados_y_por_nombre(): void
    {
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->asignacion->id}/estudiantes")->assertOk();

        $this->assertSame(['Ana López', 'Zoila Pérez'], collect($r->json('estudiantes'))->pluck('estudiante.nombre')->all());
        $this->assertSame([$this->periodo->id], collect($r->json('periodos'))->pluck('id')->all());
    }

    public function test_solo_el_docente_de_la_asignacion_y_solo_docentes(): void
    {
        $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->ajena->id}/estudiantes")->assertNotFound();
        $this->crear([], $this->ajena)->assertNotFound();

        $evaluacionAjena = Evaluacion::create(['nombre' => 'X', 'porcentaje' => 10, 'periodo_escolar_id' => $this->periodo->id, 'asignacion_id' => $this->ajena->id]);
        $this->como($this->cuentaDocente)->getJson("/api/docente/evaluaciones/{$evaluacionAjena->id}/calificaciones")->assertNotFound();
        $this->como($this->cuentaDocente)->deleteJson("/api/docente/evaluaciones/{$evaluacionAjena->id}")->assertNotFound();
        $this->guardar($evaluacionAjena->id, [['matricula_id' => $this->matriculas['Ana López'], 'nota' => 8]])->assertNotFound();

        foreach ([User::ROL_DIRECTOR, User::ROL_ENCARGADO] as $rol) {
            $this->como($this->usuario($rol))->getJson("/api/docente/asignaciones/{$this->asignacion->id}/estudiantes")->assertForbidden();
        }
        $this->assertSame(1, Evaluacion::count());
    }

    public function test_crear_evaluacion_valida_disponibilidad_del_periodo(): void
    {
        $this->crear(['porcentaje' => 60])->assertCreated();
        $this->crear(['nombre' => 'Tarea', 'porcentaje' => 40.01])->assertStatus(422)->assertJsonValidationErrors('porcentaje');
        $this->crear(['nombre' => 'Tarea', 'porcentaje' => 40])->assertCreated();
        $this->crear(['nombre' => 'Extra', 'porcentaje' => 1])->assertStatus(422)->assertJsonValidationErrors('porcentaje');

        $this->assertSame(2, Evaluacion::where('asignacion_id', $this->asignacion->id)->count());

        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->asignacion->id}/evaluaciones?periodo_escolar_id={$this->periodo->id}")->assertOk();
        $this->assertEquals(100, $r->json('porcentaje_usado'));
        $this->assertEquals(0, $r->json('porcentaje_disponible'));
        $this->assertTrue($r->json('editable'));
    }

    public function test_crear_evaluacion_valida_campos(): void
    {
        $this->crear(['nombre' => '', 'porcentaje' => 0])->assertStatus(422)->assertJsonValidationErrors(['nombre', 'porcentaje']);
        $this->crear(['porcentaje' => 100])->assertStatus(422)->assertJsonValidationErrors('porcentaje');
        $this->crear(['porcentaje' => 10.555])->assertStatus(422)->assertJsonValidationErrors('porcentaje');
        $this->crear(['nombre' => str_repeat('a', 51)])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->assertSame(0, Evaluacion::count());
    }

    public function test_periodo_de_otro_anio_o_anio_inactivo(): void
    {
        $otroAnio = $this->anio('2027');
        $ajeno = PeriodoEscolar::create(['nombre_periodo' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $otroAnio->id]);
        $this->crear(['periodo_escolar_id' => $ajeno->id])->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');

        $evaluacion = Evaluacion::create(['nombre' => 'Examen', 'porcentaje' => 30, 'periodo_escolar_id' => $this->periodo->id, 'asignacion_id' => $this->asignacion->id]);
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();

        $this->crear()->assertStatus(409);
        $this->como($this->cuentaDocente)->deleteJson("/api/docente/evaluaciones/{$evaluacion->id}")->assertStatus(409);
        $this->guardar($evaluacion->id, [['matricula_id' => $this->matriculas['Ana López'], 'nota' => 8]])->assertStatus(409);
        $this->como($this->cuentaDocente)->getJson("/api/docente/evaluaciones/{$evaluacion->id}/calificaciones")
            ->assertOk()->assertJsonPath('editable', false);
        $this->assertSame(0, Calificacion::count());
    }

    public function test_eliminar_evaluacion_sin_notas(): void
    {
        $id = $this->crear()->json('evaluacion.id');
        $otra = $this->crear(['nombre' => 'Tarea'])->json('evaluacion.id');
        $this->guardar($otra, [['matricula_id' => $this->matriculas['Ana López'], 'nota' => 7]])->assertOk();

        $this->como($this->cuentaDocente)->deleteJson("/api/docente/evaluaciones/{$otra}")->assertStatus(409);
        $this->como($this->cuentaDocente)->deleteJson("/api/docente/evaluaciones/{$id}")->assertOk();
        $this->assertSame([$otra], Evaluacion::pluck('id')->all());
    }

    public function test_registrar_y_actualizar_calificaciones(): void
    {
        $id = $this->crear()->json('evaluacion.id');
        $ana = $this->matriculas['Ana López'];
        $zoila = $this->matriculas['Zoila Pérez'];

        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 8.5], ['matricula_id' => $zoila, 'nota' => null]])
            ->assertOk()->assertJson(['registradas' => 1, 'actualizadas' => 0]);
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 9.25], ['matricula_id' => $zoila, 'nota' => 6]])
            ->assertOk()->assertJson(['registradas' => 1, 'actualizadas' => 1]);
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 9.25]])
            ->assertOk()->assertJson(['registradas' => 0, 'actualizadas' => 0]);

        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/evaluaciones/{$id}/calificaciones")->assertOk();
        $this->assertEquals([9.25, 6.0], collect($r->json('estudiantes'))->pluck('nota')->all());
        $this->assertSame(2, Calificacion::count());

        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->asignacion->id}/evaluaciones?periodo_escolar_id={$this->periodo->id}");
        $this->assertSame(2, $r->json('evaluaciones.0.calificaciones_count'));
    }

    public function test_notas_validas_y_de_la_seccion(): void
    {
        $id = $this->crear()->json('evaluacion.id');
        $ana = $this->matriculas['Ana López'];

        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 10.5]])->assertStatus(422)->assertJsonValidationErrors('notas.0.nota');
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => -1]])->assertStatus(422)->assertJsonValidationErrors('notas.0.nota');
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 7.333]])->assertStatus(422)->assertJsonValidationErrors('notas.0.nota');
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 7], ['matricula_id' => $ana, 'nota' => 8]])->assertStatus(422);
        $this->guardar($id, [])->assertStatus(422)->assertJsonValidationErrors('notas');

        // Matrícula pendiente o de otra sección: no se guarda nada
        $this->guardar($id, [['matricula_id' => $ana, 'nota' => 7], ['matricula_id' => $this->matriculas['Pedro Pendiente'], 'nota' => 8]])
            ->assertStatus(422)->assertJsonValidationErrors('notas');
        $this->assertSame(0, Calificacion::count());
    }
}
