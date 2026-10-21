<?php

namespace Tests\Feature\M2;

use App\Models\AnioLectivo;
use App\Models\PlanAsignatura;
use App\Models\Asignatura;
use App\Models\Grado;
use App\Models\PlanEstudio;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use Database\Seeders\ProfesorPruebaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SeccionPruebaSeeder;
use Database\Seeders\UsuarioPruebaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\Concerns\HoyFijo;
use Tests\TestCase;

/**
 * M2.4: gestión de secciones (decisiones D-S1 a D-S5 del 13/10/2026).
 */
class SeccionesTest extends TestCase
{
    use DatosM1, RefreshDatabase, HoyFijo;

    private User $director;
    private User $encargado;
    private array $catalogos;
    private AnioLectivo $anio;
    private Grado $primero;
    private Grado $segundo;
    private Profesor $profesor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->encargado = $this->usuario(User::ROL_ENCARGADO);
        $this->catalogos = $this->catalogosBasicos();
        // Inactivo y sin comenzar (hoy fijo 01/12/2025): sus secciones se administran
        $this->anio = $this->anio('2026');
        $this->primero = Grado::create(['nombre' => 'Primer grado']);
        $this->segundo = Grado::create(['nombre' => 'Segundo grado']);
        $this->profesor = $this->docente();
    }

    private function anio(string $nombre, string $estado = AnioLectivo::INACTIVO): AnioLectivo
    {
        $anio = AnioLectivo::create(['anio' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30",
            'inicio_matricula' => ($nombre - 2).'-01-01', 'fin_matricula' => "{$nombre}-11-30"]);
        $anio->forceFill(['estado' => $estado])->save();

        return $anio;
    }

    private function docente(): Profesor
    {
        static $n = 0;
        $n++;
        $user = $this->usuario(User::ROL_DOCENTE);

        return Profesor::create([
            'nombre' => "Docente {$n}", 'dui' => sprintf('1%07d-0', $n), 'fecha_nacimiento' => '1990-01-01',
            'codigo' => sprintf('PROF-%03d', $n), 'telefono' => sprintf('7000-%04d', $n), 'direccion' => 'X', 'user_id' => $user->id,
        ]);
    }

    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $this->primero->id, 'profesor_id' => $this->profesor->id,
        ];
    }

    private function crear(array $cambios = [])
    {
        return $this->como($this->director)->postJson('/api/secciones', $this->datos($cambios));
    }

    private function matricular(int $seccionId, string $estado = 'aprobado'): void
    {
        $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, [
            'anio_lectivo_id' => $this->anio->id, 'seccion_id' => $seccionId, 'estado' => $estado,
        ]);
    }

    private function asignar(int $seccionId): void
    {
        $plan = PlanEstudio::create([
            'nombre' => 'Plan', 'grado_id' => $this->primero->id, 'estado' => true, 'fecha_inicio' => '2026-01-15',
            'fecha_fin' => '2026-11-30', 'fecha_aprobacion' => '2025-12-01', 'descripcion' => 'Pensum',
        ]);
        $asignatura = PlanAsignatura::enlazar($plan->id, 'Lenguaje');
        DB::table('asignaciones')->insert(['profesor_id' => $this->profesor->id, 'plan_asignatura_id' => $asignatura->id, 'seccion_id' => $seccionId]);
    }

    // --- Crear ---

    public function test_director_crea_seccion_con_nombre_normalizado(): void
    {
        $this->crear(['nombre' => '  b ', 'turno' => 'Matutino'])->assertCreated()
            ->assertJsonPath('seccion.nombre', 'B')
            ->assertJsonPath('seccion.turno', 'matutino')
            ->assertJsonPath('seccion.grado.nombre', 'Primer grado')
            ->assertJsonPath('seccion.matriculados', 0)
            ->assertJsonPath('seccion.cupos_disponibles', 30);
    }

    public function test_capacidad_entre_1_y_50(): void
    {
        foreach ([0, -5, 51, 'treinta'] as $capacidad) {
            $this->crear(['capacidad' => $capacidad])->assertStatus(422)->assertJsonValidationErrors('capacidad');
        }
        $this->crear(['capacidad' => 1])->assertCreated();
        $this->crear(['nombre' => 'B', 'capacidad' => 50, 'turno' => 'vespertino'])->assertCreated();
    }

    public function test_datos_invalidos_responden_422(): void
    {
        $this->crear(['turno' => 'nocturno'])->assertStatus(422)->assertJsonValidationErrors('turno');
        $this->crear(['nombre' => 'ABCDEFGHIJK'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->crear(['grado_id' => 999])->assertStatus(422)->assertJsonValidationErrors('grado_id');
        $this->crear(['anio_lectivo_id' => 999])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->assertSame(0, Seccion::count());
    }

    public function test_nombre_unico_por_grado_y_anio_sin_importar_turno(): void
    {
        $this->crear()->assertCreated();

        // D-S1: "a" vespertina choca con "A" matutina del mismo grado y año
        $otro = $this->docente();
        $this->crear(['nombre' => 'a', 'turno' => 'vespertino', 'profesor_id' => $otro->id])
            ->assertStatus(409)->assertJsonPath('message', 'La sección ya está registrada para este grado en el año lectivo.');

        // Otro grado u otro año sí admiten la misma letra
        $this->crear(['grado_id' => $this->segundo->id, 'profesor_id' => $otro->id])->assertCreated();
        $otroAnio = $this->anio('2028');
        $this->crear(['anio_lectivo_id' => $otroAnio->id])->assertCreated();
    }

    public function test_docente_responsable_de_una_seccion_por_turno_y_anio(): void
    {
        $this->crear()->assertCreated();

        // D-S2: mismo docente, mismo turno y año
        $this->crear(['grado_id' => $this->segundo->id])->assertStatus(409);

        // Otro turno u otro año sí
        $this->crear(['grado_id' => $this->segundo->id, 'turno' => 'vespertino'])->assertCreated();
        $this->crear(['anio_lectivo_id' => $this->anio('2028')->id])->assertCreated();
    }

    public function test_docente_debe_tener_cuenta_docente(): void
    {
        // Una cuenta que ya no tiene el rol DOCENTE no sirve
        $sinRol = $this->docente();
        $sinRol->user->asignarRol(User::ROL_ENCARGADO);
        $sinRol->user->save();
        $this->crear(['profesor_id' => $sinRol->id])->assertStatus(422)->assertJsonValidationErrors('profesor_id');
    }

    public function test_no_se_crean_secciones_en_un_anio_cerrado(): void
    {
        $cerrado = $this->anio('2025', AnioLectivo::CIERRE);

        $this->crear(['anio_lectivo_id' => $cerrado->id])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
    }

    public function test_no_exige_plan_de_estudios_vigente(): void
    {
        // D-S5
        $this->assertSame(0, PlanEstudio::count());
        $this->crear()->assertCreated();
    }

    // --- Editar ---

    public function test_capacidad_no_baja_de_los_matriculados(): void
    {
        $id = $this->crear(['capacidad' => 5])->json('seccion.id');
        $this->matricular($id);
        $this->matricular($id, 'pendiente');
        $this->matricular($id, 'rechazado');

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['capacidad' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('capacidad');

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['capacidad' => 2]))->assertOk()
            ->assertJsonPath('seccion.matriculados', 2)
            ->assertJsonPath('seccion.cupos_disponibles', 0);
    }

    public function test_con_matriculas_no_cambia_grado_ni_turno(): void
    {
        $id = $this->crear()->json('seccion.id');
        $this->matricular($id, 'rechazado');

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['grado_id' => $this->segundo->id]))->assertStatus(409);
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['turno' => 'vespertino']))->assertStatus(409);

        // El docente responsable sí puede cambiar
        $otro = $this->docente();
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['profesor_id' => $otro->id]))->assertOk()
            ->assertJsonPath('seccion.profesor.id', $otro->id);
    }

    public function test_con_asignaciones_no_cambia_grado_ni_turno(): void
    {
        $id = $this->crear()->json('seccion.id');
        $this->asignar($id);

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['grado_id' => $this->segundo->id]))->assertStatus(409);
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['turno' => 'vespertino']))->assertStatus(409);
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['nombre' => 'C']))->assertOk();
    }

    public function test_sin_dependencias_puede_cambiar_grado_y_turno(): void
    {
        $id = $this->crear()->json('seccion.id');

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['grado_id' => $this->segundo->id, 'turno' => 'vespertino']))->assertOk()
            ->assertJsonPath('seccion.grado_id', $this->segundo->id)
            ->assertJsonPath('seccion.turno', 'vespertino');
    }

    public function test_editar_respeta_unicidad_y_docente(): void
    {
        $a = $this->crear()->json('seccion.id');
        $otro = $this->docente();
        $b = $this->crear(['nombre' => 'B', 'profesor_id' => $otro->id])->json('seccion.id');

        $this->como($this->director)->putJson("/api/secciones/{$b}", $this->datos(['nombre' => 'a', 'profesor_id' => $otro->id]))->assertStatus(409);
        $this->como($this->director)->putJson("/api/secciones/{$b}", $this->datos(['nombre' => 'B']))->assertStatus(409);

        // Guardar la misma sección sin cambios no choca consigo misma
        $this->como($this->director)->putJson("/api/secciones/{$a}", $this->datos())->assertOk();
    }

    public function test_seccion_de_anio_cerrado_no_se_edita_ni_elimina(): void
    {
        $id = $this->crear()->json('seccion.id');
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();

        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['capacidad' => 40]))
            ->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->como($this->director)->deleteJson("/api/secciones/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('secciones', ['id' => $id, 'capacidad' => 30]);
    }

    // --- Eliminar ---

    public function test_eliminar_respeta_matriculas_y_asignaciones(): void
    {
        $conMatricula = $this->crear()->json('seccion.id');
        $this->matricular($conMatricula);
        $this->como($this->director)->deleteJson("/api/secciones/{$conMatricula}")->assertStatus(409)
            ->assertJsonPath('message', 'No se puede eliminar la sección porque tiene estudiantes matriculados.');

        $conAsignacion = $this->crear(['nombre' => 'B', 'turno' => 'vespertino'])->json('seccion.id');
        $this->asignar($conAsignacion);
        $this->como($this->director)->deleteJson("/api/secciones/{$conAsignacion}")->assertStatus(409);

        $libre = $this->crear(['nombre' => 'C', 'profesor_id' => $this->docente()->id])->json('seccion.id');
        $this->como($this->director)->deleteJson("/api/secciones/{$libre}")->assertOk();
        $this->assertDatabaseMissing('secciones', ['id' => $libre]);
        $this->como($this->director)->deleteJson("/api/secciones/{$libre}")->assertNotFound();
    }

    // --- Listado y ocupación ---

    public function test_listado_por_defecto_del_anio_activo_con_filtros_y_ocupacion(): void
    {
        $otroAnio = $this->anio('2028');
        $b = $this->crear(['nombre' => 'B', 'grado_id' => $this->segundo->id])->json('seccion.id');
        $a = $this->crear(['profesor_id' => $this->docente()->id])->json('seccion.id');
        $v = $this->crear(['nombre' => 'A', 'grado_id' => $this->segundo->id, 'turno' => 'vespertino', 'profesor_id' => $this->docente()->id])->json('seccion.id');
        $this->crear(['anio_lectivo_id' => $otroAnio->id]);
        $this->matricular($a);
        $this->anio->forceFill(['estado' => AnioLectivo::ACTIVO])->save();

        // Año activo, en orden académico: Primer grado A, Segundo grado B (matutino), Segundo grado A (vespertino)
        $this->como($this->director)->getJson('/api/secciones')->assertOk()
            ->assertJsonPath('anio_lectivo_id', $this->anio->id)
            ->assertJsonCount(3, 'secciones')
            ->assertJsonPath('secciones.0.id', $a)
            ->assertJsonPath('secciones.0.matriculados', 1)
            ->assertJsonPath('secciones.0.cupos_disponibles', 29)
            ->assertJsonPath('secciones.1.id', $b)
            ->assertJsonPath('secciones.2.id', $v);

        $this->como($this->director)->getJson("/api/secciones?grado_id={$this->segundo->id}&turno=vespertino")->assertOk()
            ->assertJsonCount(1, 'secciones')->assertJsonPath('secciones.0.id', $v);

        $this->como($this->director)->getJson("/api/secciones?anio_lectivo_id={$otroAnio->id}")->assertOk()
            ->assertJsonCount(1, 'secciones');

        $this->como($this->director)->getJson('/api/secciones?turno=nocturno')->assertStatus(422);
    }

    public function test_detalle_incluye_ocupacion(): void
    {
        $id = $this->crear(['capacidad' => 10])->json('seccion.id');
        $this->matricular($id, 'pendiente');

        $this->como($this->director)->getJson("/api/secciones/{$id}")->assertOk()
            ->assertJsonPath('seccion.matriculados', 1)
            ->assertJsonPath('seccion.cupos_disponibles', 9)
            ->assertJsonPath('seccion.anio_lectivo.anio', 2026);
        $this->como($this->director)->getJson('/api/secciones/999')->assertNotFound();
    }

    public function test_secciones_se_bloquean_cuando_su_anio_comenzo(): void
    {
        // Año activo cuyas fechas aún no comienzan (hoy 01/12/2025): sí se administra (20/10/2026)
        $this->anio->forceFill(['estado' => AnioLectivo::ACTIVO])->save();
        $id = $this->crear()->assertCreated()->json('seccion.id');
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['capacidad' => 25]))->assertOk();
        $borrable = $this->crear(['nombre' => 'C', 'turno' => 'vespertino'])->assertCreated()->json('seccion.id');
        $this->como($this->director)->deleteJson("/api/secciones/{$borrable}")->assertOk();

        // Desde la fecha de inicio del año (15/01/2026) sus secciones quedan fijas
        $this->fijarHoy('2026-01-15');
        $bloqueo = 'El año lectivo 2026 ya comenzó (15/01/2026): sus secciones no se pueden crear, modificar ni eliminar.';
        $this->crear(['nombre' => 'B'])->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->como($this->director)->putJson("/api/secciones/{$id}", $this->datos(['capacidad' => 20]))->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->como($this->director)->deleteJson("/api/secciones/{$id}")->assertStatus(409)->assertJsonPath('message', $bloqueo);
        $this->assertSame(25, Seccion::find($id)->capacidad);

        // Las del año siguiente, que aún no comienza, se siguen preparando
        $siguiente = $this->anio('2027');
        $otra = $this->crear(['nombre' => 'B', 'anio_lectivo_id' => $siguiente->id])->assertCreated()->json('seccion.id');
        $this->como($this->director)->putJson("/api/secciones/{$otra}", $this->datos(['nombre' => 'B', 'capacidad' => 20, 'anio_lectivo_id' => $siguiente->id]))->assertOk();
        // ...pero no se pasan a un año ya comenzado
        $this->como($this->director)->putJson("/api/secciones/{$otra}", $this->datos(['nombre' => 'B', 'capacidad' => 20]))->assertStatus(409)->assertJsonPath('message', $bloqueo);

        // La consulta sigue disponible
        $this->como($this->director)->getJson('/api/secciones?anio_lectivo_id='.$this->anio->id)->assertOk()->assertJsonCount(1, 'secciones');
    }

    public function test_solo_el_director_gestiona_secciones(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE);
        $this->como($docente)->getJson('/api/secciones')->assertForbidden();
        $this->como($this->encargado)->postJson('/api/secciones', $this->datos())->assertForbidden();
    }

    // --- Seeder ---

    public function test_seeder_de_secciones_es_reproducible(): void
    {
        $this->seed([RoleSeeder::class, UsuarioPruebaSeeder::class, ProfesorPruebaSeeder::class]);

        $this->seed(SeccionPruebaSeeder::class);
        $this->seed(SeccionPruebaSeeder::class);

        $profesor = User::where('email', 'docente@siga.test')->first()->profesor;
        $secciones = Seccion::where('profesor_id', $profesor->id)->where('anio_lectivo_id', $this->anio->id)->get();
        $this->assertCount(2, $secciones);
        $this->assertEqualsCanonicalizing(['matutino', 'vespertino'], $secciones->pluck('turno')->all());
    }

    public function test_seeder_crea_el_anio_de_prueba_si_no_hay_anio_abierto(): void
    {
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->seed([RoleSeeder::class, UsuarioPruebaSeeder::class, ProfesorPruebaSeeder::class, SeccionPruebaSeeder::class]);

        $prueba = AnioLectivo::where('anio', SeccionPruebaSeeder::ANIO_PRUEBA)->first();
        $this->assertSame(AnioLectivo::INACTIVO, $prueba->estado);
        $this->assertTrue($prueba->periodos()->exists());
        $this->assertSame(2, Seccion::where('anio_lectivo_id', $prueba->id)->count());
    }
}
