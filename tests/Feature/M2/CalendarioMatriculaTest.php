<?php

namespace Tests\Feature\M2;

use App\Models\AnioLectivo;
use App\Models\Grado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.2: secciones y matrículas usan el año lectivo, no el reloj del servidor.
 */
class CalendarioMatriculaTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    private User $director;
    private User $encargado;
    private array $catalogos;
    private Grado $grado;
    private int $profesor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->encargado = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000001-8']);
        $this->catalogos = $this->catalogosBasicos();
        $this->grado = Grado::create(['nombre' => 'Primer grado']);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $this->profesor = DB::table('profesores')->insertGetId([
            'nombre' => 'Profe', 'dui' => '00000002-6', 'fecha_nacimiento' => '1990-01-01', 'codigo' => 'P1',
            'telefono' => '70000000', 'direccion' => 'X', 'user_id' => $docente->id,
        ]);
    }

    private function anio(string $nombre, string $estado = AnioLectivo::INACTIVO): AnioLectivo
    {
        $anio = AnioLectivo::create(['nombre' => $nombre, 'fecha_inicio' => "{$nombre}-01-15", 'fecha_fin' => "{$nombre}-11-30"]);
        $anio->forceFill(['estado' => $estado])->save();

        return $anio;
    }

    private function seccion(array $datos)
    {
        return $this->como($this->director)->postJson('/api/secciones', $datos + [
            'nombre' => 'A', 'capacidad' => 2, 'turno' => 'matutino', 'grado_id' => $this->grado->id, 'profesor_id' => $this->profesor,
        ]);
    }

    private function matricular(array $datos = [])
    {
        $estudiante = $this->estudianteDe($this->encargado, $this->catalogos['distrito']);

        return $this->como($this->encargado)->post('/api/matriculas', $datos + [
            'ingreso' => 'Nuevo ingreso',
            'estudiante_id' => $estudiante->id,
            'enfermedad_id' => $this->catalogos['enfermedad'],
            'especialidad_id' => $this->catalogos['especialidad'],
            'foto' => UploadedFile::fake()->image('foto.jpg'),
            'es_trasladado' => 0,
        ], ['Accept' => 'application/json']);
    }

    public function test_seccion_pertenece_a_un_anio_lectivo(): void
    {
        $anio = $this->anio('2027', AnioLectivo::ACTIVO);

        $this->seccion(['anio_lectivo_id' => $anio->id])->assertCreated()
            ->assertJsonPath('seccion.anio_lectivo_id', $anio->id);

        // El mismo nombre en el mismo año se rechaza (409, M2.4); en otro año está permitido
        $this->seccion(['anio_lectivo_id' => $anio->id])->assertStatus(409);
        $otro = $this->anio('2028');
        $this->seccion(['anio_lectivo_id' => $otro->id])->assertCreated();

        // El año lectivo es obligatorio y debe existir
        $this->seccion(['nombre' => 'C'])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->seccion(['nombre' => 'C', 'anio_lectivo_id' => 9999])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        // El nombre de la sección mide hasta 10 caracteres (diagrama)
        $this->seccion(['nombre' => str_repeat('A', 11), 'anio_lectivo_id' => $anio->id])->assertStatus(422)->assertJsonValidationErrors('nombre');

        // Un año cerrado no admite secciones nuevas
        $cerrado = $this->anio('2026', AnioLectivo::CIERRE);
        $this->seccion(['nombre' => 'D', 'anio_lectivo_id' => $cerrado->id])->assertStatus(422);
    }

    public function test_secciones_disponibles_usan_el_anio_lectivo_y_no_el_reloj(): void
    {
        // Año activo distinto del año del servidor
        $activo = $this->anio((string) (now()->year + 1), AnioLectivo::ACTIVO);
        $inactivo = $this->anio((string) (now()->year + 2));
        $a = $this->seccion(['anio_lectivo_id' => $activo->id])->json('seccion.id');
        $b = $this->seccion(['nombre' => 'B', 'anio_lectivo_id' => $inactivo->id])->json('seccion.id');

        // Una matrícula de la sección A ocupa un cupo
        $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, [
            'anio_lectivo_id' => $activo->id, 'seccion_id' => $a,
        ]);

        $consulta = ['grado_id' => $this->grado->id, 'turno' => 'matutino'];
        $this->como($this->encargado)->postJson('/api/matriculas/secciones-disponibles', $consulta)->assertOk()
            ->assertJsonPath('secciones.0.id', $a)->assertJsonPath('secciones.0.cupos_disponibles', 1)->assertJsonCount(1, 'secciones');
        $this->como($this->encargado)->postJson('/api/matriculas/disponibilidad-seccion', $consulta)->assertOk()
            ->assertJsonPath('disponibilidad', true)->assertJsonPath('cupos_disponibles', 1);

        // Un año inactivo (por iniciar) puede consultarse indicándolo
        $this->como($this->encargado)->postJson('/api/matriculas/secciones-disponibles', $consulta + ['anio_lectivo_id' => $inactivo->id])->assertOk()
            ->assertJsonPath('secciones.0.id', $b)->assertJsonPath('secciones.0.cupos_disponibles', 2);

        // Sin año activo la respuesta lo dice con claridad
        $activo->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->como($this->encargado)->postJson('/api/matriculas/secciones-disponibles', $consulta)
            ->assertStatus(422)->assertJsonPath('errors.anio_lectivo_id.0', 'No hay un año lectivo activo. Indique el año lectivo de la matrícula.');
    }

    public function test_matricula_toma_el_anio_lectivo_y_valida_la_seccion(): void
    {
        // Sin año activo ni indicado no se matricula
        $this->matricular()->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->assertSame(0, DB::table('matriculas')->count());

        $activo = $this->anio((string) (now()->year + 1), AnioLectivo::ACTIVO);
        $inactivo = $this->anio((string) (now()->year + 2));
        $seccionActivo = $this->seccion(['anio_lectivo_id' => $activo->id])->json('seccion.id');
        $seccionInactivo = $this->seccion(['anio_lectivo_id' => $inactivo->id])->json('seccion.id');

        // Por defecto, el año activo (no el del reloj)
        $id = $this->matricular(['seccion_id' => $seccionActivo])->assertCreated()->json('matricula.id');
        $fila = DB::table('matriculas')->find($id);
        $this->assertSame($activo->id, (int) $fila->anio_lectivo_id);

        // Sección de otro año: rechazada
        $this->matricular(['seccion_id' => $seccionInactivo])
            ->assertStatus(422)->assertJsonPath('errors.seccion_id.0', 'La sección no pertenece al año lectivo de la matrícula.');

        // Año inactivo indicado expresamente, con su sección
        $this->matricular(['anio_lectivo_id' => $inactivo->id, 'seccion_id' => $seccionInactivo])->assertCreated()
            ->assertJsonPath('matricula.anio_lectivo_id', $inactivo->id);

        // Año cerrado o inexistente: rechazado
        $cerrado = $this->anio((string) (now()->year - 1), AnioLectivo::CIERRE);
        $this->matricular(['anio_lectivo_id' => $cerrado->id])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->matricular(['anio_lectivo_id' => 9999])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');
        $this->assertSame(2, DB::table('matriculas')->count());
    }

    public function test_matricula_anticipada_en_anio_inactivo_sin_anio_activo(): void
    {
        $inactivo = $this->anio((string) (now()->year + 1));
        $seccion = $this->seccion(['anio_lectivo_id' => $inactivo->id])->json('seccion.id');
        $this->assertNull(AnioLectivo::activo());

        // Con el año inactivo indicado expresamente: se admite
        $id = $this->matricular(['anio_lectivo_id' => $inactivo->id, 'seccion_id' => $seccion])->assertCreated()
            ->assertJsonPath('matricula.anio_lectivo_id', $inactivo->id)->json('matricula.id');
        $fila = DB::table('matriculas')->find($id);
        $this->assertSame((int) $seccion, (int) $fila->seccion_id);

        // Las demás validaciones siguen aplicándose
        $this->matricular(['anio_lectivo_id' => $inactivo->id, 'ingreso' => 'Tío(a)'])
            ->assertStatus(422)->assertJsonValidationErrors('ingreso');

        // Sin indicar año y sin año activo: error controlado, nada se guarda
        $this->matricular(['seccion_id' => $seccion])->assertStatus(422)
            ->assertJsonPath('errors.anio_lectivo_id.0', 'No hay un año lectivo activo. Indique el año lectivo de la matrícula.');
        $this->assertSame(1, DB::table('matriculas')->count());
    }

    public function test_sin_anio_indicado_usa_el_activo(): void
    {
        $activo = $this->anio((string) (now()->year), AnioLectivo::ACTIVO);
        $this->anio((string) (now()->year + 1));

        $this->matricular()->assertCreated()->assertJsonPath('matricula.anio_lectivo_id', $activo->id);
    }

    public function test_duplicidad_por_anio_lectivo(): void
    {
        $anio = $this->anio('2027', AnioLectivo::ACTIVO);
        $estudiante = $this->estudianteDe($this->encargado, $this->catalogos['distrito']);
        $this->matriculaDe($estudiante, $this->catalogos, ['anio_lectivo_id' => $anio->id]);
        $otro = $this->anio('2028');

        $this->como($this->encargado)->postJson('/api/matriculas/duplicidad', ['estudiante_id' => $estudiante->id, 'anio_lectivo_id' => $anio->id])
            ->assertStatus(422)->assertJsonPath('duplicidad', true);
        $this->como($this->encargado)->postJson('/api/matriculas/duplicidad', ['estudiante_id' => $estudiante->id, 'anio_lectivo_id' => $otro->id])
            ->assertOk()->assertJsonPath('duplicidad', false);
        // Sin indicar año se usa el activo
        $this->como($this->encargado)->postJson('/api/matriculas/duplicidad', ['estudiante_id' => $estudiante->id])
            ->assertStatus(422)->assertJsonPath('duplicidad', true);
    }

    public function test_seccion_con_matriculas_no_cambia_de_anio(): void
    {
        $a = $this->anio('2027', AnioLectivo::ACTIVO);
        $b = $this->anio('2028');
        $seccion = $this->seccion(['anio_lectivo_id' => $a->id])->json('seccion.id');
        $this->matriculaDe($this->estudianteDe($this->encargado, $this->catalogos['distrito']), $this->catalogos, [
            'anio_lectivo_id' => $a->id, 'seccion_id' => $seccion,
        ]);

        $this->como($this->director)->putJson("/api/secciones/{$seccion}", [
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $this->grado->id, 'profesor_id' => $this->profesor, 'anio_lectivo_id' => $b->id,
        ])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo_id');

        $this->como($this->director)->putJson("/api/secciones/{$seccion}", [
            'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'grado_id' => $this->grado->id, 'profesor_id' => $this->profesor, 'anio_lectivo_id' => $a->id,
        ])->assertOk()->assertJsonPath('seccion.capacidad', 30);
    }
}
