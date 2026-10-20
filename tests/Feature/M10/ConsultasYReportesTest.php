<?php

namespace Tests\Feature\M10;

use App\Models\Boleta;
use App\Models\Conducta;
use App\Models\Discapacidad;
use App\Models\Expediente;
use App\Models\NotaFinal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * M10 Encargado (Consultar notas, Consultar conducta), M11 (Consultar expediente,
 * Matricula.generarComprobante()) y M12 (reportes de notas y de conducta).
 */
class ConsultasYReportesTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->director = $this->usuario(User::ROL_DIRECTOR);

        $this->conNotas($this->matematica, $this->periodos[0], [
            [40, ['Ana López' => 8, 'Zoila Pérez' => 6]],
            [60, ['Ana López' => 9, 'Zoila Pérez' => 7]],
        ]);
        $this->como($this->cuentaDocente)->postJson("/api/docente/asignaciones/{$this->matematica->id}/libreta", ['periodo_escolar_id' => $this->periodos[0]->id])->assertOk();
        Conducta::create(['descripcion' => 'Respetuosa', 'valoracion' => 'Excelente', 'matricula_id' => $this->matriculas['Ana López'], 'periodo_escolar_id' => $this->periodos[0]->id]);
    }

    public function test_encargado_consulta_notas_de_su_estudiante(): void
    {
        $id = $this->estudiantes['Ana López']->id;
        $r = $this->como($this->encargado)->getJson("/api/estudiantes/{$id}/notas")->assertOk();

        $this->assertSame($this->matriculas['Ana López'], $r->json('matricula.id'));
        $this->assertSame(['Lenguaje', 'Matemática'], collect($r->json('materias'))->pluck('asignatura')->all());
        $mate = collect($r->json('materias'))->firstWhere('asignatura', 'Matemática');
        $this->assertEquals([8, 9], collect($mate['periodos'][0]['evaluaciones'])->pluck('nota')->all());
        $this->assertEquals(8.6, $mate['periodos'][0]['promedio']);
        $this->assertNull($mate['periodos'][1]['promedio']);
        $this->assertNull($r->json('nota_final'));
    }

    public function test_encargado_consulta_conducta(): void
    {
        $id = $this->estudiantes['Ana López']->id;
        $r = $this->como($this->encargado)->getJson("/api/estudiantes/{$id}/conducta")->assertOk();

        $this->assertSame(['descripcion' => 'Respetuosa', 'valoracion' => 'Excelente'], $r->json('periodos.0.conducta'));
        $this->assertNull($r->json('periodos.1.conducta'));
    }

    public function test_pendiente_sin_notas_y_lo_ajeno_no_se_ve(): void
    {
        $pedro = $this->estudiantes['Pedro Pendiente']->id;
        $this->como($this->encargado)->getJson("/api/estudiantes/{$pedro}/notas")->assertOk()
            ->assertJsonPath('matricula', null)->assertJsonPath('materias', []);

        $otro = $this->usuario(User::ROL_ENCARGADO);
        $ana = $this->estudiantes['Ana López']->id;
        $this->como($otro)->getJson("/api/estudiantes/{$ana}/notas")->assertNotFound();
        $this->como($otro)->getJson("/api/estudiantes/{$ana}/conducta")->assertNotFound();
        $this->como($otro)->getJson("/api/matriculas/{$this->matriculas['Ana López']}/comprobante")->assertNotFound();
        $this->como($this->encargado)->getJson("/api/estudiantes/{$ana}/notas?matricula_id={$this->matriculas['Zoila Pérez']}")->assertNotFound();
        $this->como($this->cuentaDocente)->getJson("/api/estudiantes/{$ana}/notas")->assertForbidden();

        // El director consulta a cualquiera
        $this->como($this->director)->getJson("/api/estudiantes/{$ana}/notas")->assertOk();
    }

    public function test_asignaturas_y_filtro_solo_estudiantes_propios_aprobados(): void
    {
        $ana = $this->estudiantes['Ana López']->id;
        $this->como($this->encargado)->getJson("/api/estudiantes/{$ana}/asignaturas")->assertOk()
            ->assertJsonPath('asignaturas.0.asignatura', 'Lenguaje')
            ->assertJsonPath('asignaturas.1.asignatura', 'Matemática')
            ->assertJsonCount(2, 'asignaturas');
        $pedro = $this->estudiantes['Pedro Pendiente']->id;
        $this->como($this->encargado)->getJson("/api/estudiantes/{$pedro}/asignaturas")->assertOk()->assertJsonPath('asignaturas', []);

        // El filtro de estudiantes de Notas y Asignaturas: solo los de la cuenta y con matrícula aprobada
        $otro = $this->usuario(User::ROL_ENCARGADO);
        $catalogos = ['distrito' => $this->estudiantes['Ana López']->distrito_id, 'especialidad' => null, 'enfermedad' => null];
        $ajeno = $this->estudianteDe($otro, $catalogos['distrito'], ['nombre' => 'Ajeno Aprobado']);
        $this->matriculaDe($ajeno, $catalogos, ['estado' => 'aprobado', 'seccion_id' => $this->seccion->id, 'anio_lectivo_id' => $this->anio->id]);
        $nombres = collect($this->como($this->encargado)->getJson('/api/estudiantes?con_matricula_aprobada=1')->assertOk()->json('estudiantes'))->pluck('nombre')->all();
        $this->assertSame(['Ana López', 'Zoila Pérez'], $nombres);
        $this->como($otro)->getJson("/api/estudiantes/{$ana}/asignaturas")->assertNotFound();
    }

    public function test_estado_de_matricula_de_la_cuenta_para_el_menu(): void
    {
        // Con una solicitud pendiente en el año: menú de solicitud
        $this->como($this->encargado)->getJson('/api/familia/perfil')->assertJsonPath('estado_matricula', 'pendiente');

        // Sin pendientes y con aprobadas: menú de perfil, notas y asignaturas
        $this->como($this->director)->putJson("/api/matriculas/{$this->matriculas['Pedro Pendiente']}/estado", ['estado' => 'rechazado'])->assertOk();
        $this->como($this->encargado)->getJson('/api/familia/perfil')->assertJsonPath('estado_matricula', 'aprobada');

        // Cuenta sin matrículas
        $this->como($this->usuario(User::ROL_ENCARGADO))->getJson('/api/familia/perfil')->assertJsonPath('estado_matricula', 'sin_matricula');
    }

    public function test_comprobante_de_matricula(): void
    {
        $id = $this->matriculas['Ana López'];
        $r = $this->como($this->encargado)->getJson("/api/matriculas/{$id}/comprobante")->assertOk();

        $this->assertSame(sprintf('MAT-2026-%06d', $id), $r->json('comprobante'));
        $this->assertSame('Ana López', $r->json('estudiante.nombre'));
        $this->assertSame('Primer grado', $r->json('grado'));
        $this->assertSame('A', $r->json('seccion'));
    }

    public function test_expediente_solo_director(): void
    {
        $discapacidad = Discapacidad::create(['nombre' => 'Visual']);
        Expediente::create(['discapacidad_id' => $discapacidad->id, 'matricula_id' => $this->matriculas['Ana López']]);
        NotaFinal::create(['matricula_id' => $this->matriculas['Ana López'], 'promedio_anual' => 8.6, 'estado' => 'Aprobada']);
        $id = $this->estudiantes['Ana López']->id;

        $r = $this->como($this->director)->getJson("/api/estudiantes/{$id}/expediente")->assertOk();
        $this->assertSame('Ana López', $r->json('estudiante.nombre'));
        $this->assertSame(['Visual'], collect($r->json('estudiante.matriculas.0.discapacidades'))->pluck('nombre')->all());
        $this->assertEquals(['promedio_anual' => 8.6, 'estado' => 'Aprobada'], $r->json('resultados.0.nota_final'));
        $this->assertSame('Excelente', $r->json('resultados.0.conducta.0.valoracion'));

        $this->como($this->encargado)->getJson("/api/estudiantes/{$id}/expediente")->assertForbidden();
    }

    public function test_reporte_de_notas_director_y_docente(): void
    {
        $q = "seccion_id={$this->seccion->id}&periodo_escolar_id={$this->periodos[0]->id}";
        $r = $this->como($this->director)->getJson("/api/reportes/notas?{$q}")->assertOk();
        $this->assertSame(['Lenguaje', 'Matemática'], collect($r->json('materias'))->pluck('nombre')->all());
        $ana = $r->json('estudiantes.0');
        $this->assertSame('Ana López', $ana['estudiante']['nombre']);
        $this->assertEquals(8.6, $ana['promedios'][$this->matematica->id]);
        $this->assertNull($ana['promedios'][$this->lenguaje->id]);
        $this->assertEquals(8.6, $ana['promedio']);

        // El docente ve solo sus materias
        $r = $this->como($this->cuentaOtroDocente)->getJson("/api/docente/reportes/notas?{$q}")->assertOk();
        $this->assertSame(['Lenguaje'], collect($r->json('materias'))->pluck('nombre')->all());

        // El guía de la sección ve todas sus materias (libreta de notas completa) y su nombre
        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/reportes/notas?{$q}")->assertOk();
        $this->assertSame(['Lenguaje', 'Matemática'], collect($r->json('materias'))->pluck('nombre')->all());
        $this->assertSame($this->seccion->profesor->nombre, $r->json('seccion.profesor.nombre'));

        [$ajeno] = $this->docenteNuevo(3);
        $this->como($ajeno)->getJson("/api/docente/reportes/notas?{$q}")->assertNotFound();
        $this->como($this->cuentaDocente)->getJson("/api/reportes/notas?{$q}")->assertForbidden();
        $this->como($this->director)->getJson("/api/reportes/notas?seccion_id={$this->seccion->id}")->assertStatus(422);
    }

    public function test_reporte_de_conducta(): void
    {
        $q = "seccion_id={$this->seccion->id}&periodo_escolar_id={$this->periodos[0]->id}";
        foreach (['/api/reportes/conducta' => $this->director, '/api/docente/reportes/conducta' => $this->cuentaDocente] as $ruta => $cuenta) {
            $r = $this->como($cuenta)->getJson("{$ruta}?{$q}")->assertOk();
            $this->assertSame('Excelente', $r->json('estudiantes.0.conducta.valoracion'));
            $this->assertNull($r->json('estudiantes.1.conducta'));
        }
        $this->assertSame(2, Boleta::count());
    }
}
