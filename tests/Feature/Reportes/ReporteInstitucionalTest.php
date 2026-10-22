<?php

namespace Tests\Feature\Reportes;

use App\Models\Boleta;
use App\Models\Matricula;
use App\Models\User;
use App\Services\Admin\ReportesAdminPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte Institucional del director: indicadores del nivel en el período que
 * el formulario toma de la base (matrícula, nuevos ingresos, boletas, docentes).
 */
class ReporteInstitucionalTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    public function test_indicadores_por_nivel_y_periodo(): void
    {
        $this->prepararSeccion();
        $director = $this->usuario(User::ROL_DIRECTOR);
        Matricula::whereKey($this->matriculas['Zoila Pérez'])->update(['ingreso' => 'Reingreso']);

        $periodo = $this->periodos[0]->id;
        foreach ([['Ana López', 9, 9.5], ['Zoila Pérez', 6, 6.5]] as [$nombre, $matematica, $lenguaje]) {
            Boleta::create(['promedio' => $matematica, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
            Boleta::create(['promedio' => $lenguaje, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->lenguaje->id, 'periodo_escolar_id' => $periodo]);
        }
        $url = fn (string $nivel, int $periodo) => '/api/reportes/institucional/indicadores?nivel='.urlencode($nivel).'&periodo_escolar_id='.$periodo;

        // Pedro Pendiente no cuenta; Ana 9.25 destacada, Zoila 6.25 reprueba
        $this->como($director)->getJson($url('Educación Básica', $periodo))->assertOk()->assertExactJson([
            'total_estudiantes' => 2, 'nuevos_ingresos' => 1, 'estudiantes_evaluados' => 2, 'promedio_general' => 7.75,
            'estudiantes_destacados' => 1, 'estudiantes_reprobados' => 1, 'total_docentes' => 2,
        ]);

        // Período sin boletas y nivel sin secciones
        $r = $this->como($director)->getJson($url('Educación Básica', $this->periodos[1]->id))->assertOk();
        $this->assertSame(2, $r->json('total_estudiantes'));
        $this->assertNull($r->json('promedio_general'));
        $this->assertSame(0, $r->json('estudiantes_reprobados'));
        $r = $this->como($director)->getJson($url('Parvularia', $periodo))->assertOk();
        $this->assertSame(0, $r->json('total_estudiantes'));
        $this->assertSame(0, $r->json('total_docentes'));

        $this->como($director)->getJson($url('Media', $periodo))->assertStatus(422);
        $this->como($director)->getJson($url('Bachillerato', 999))->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson($url('Educación Básica', $periodo))->assertForbidden();
    }

    public function test_pdf_del_reporte_institucional(): void
    {
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-06-12 15:00:00', 'UTC'));
        $director = $this->usuario(User::ROL_DIRECTOR);
        $datos = [
            'nivel' => 'Educación Básica', 'periodo_escolar_id' => $this->periodos[0]->id,
            'nombre' => 'María Fernanda López', 'cargo' => 'Directora',
            'descripcion_calificaciones' => 'Desempeño favorable.', 'incidentes' => 4, 'reportes_leves' => 3, 'reportes_graves' => 1, 'medidas_aplicadas' => '',
            'descripcion_casos' => 'Casos atendidos.', 'total_actividades' => 6, 'total_proyectos' => 2,
            'actividades' => ['Torneo Deportivo', 'Acto Cívico'], 'descripcion_actividades' => 'Feria del libro.',
            'docentes_ausentes' => 1, 'observaciones' => 'Clima favorable.',
        ];

        $r = $this->como($director)->postJson('/api/reportes/institucional/pdf', $datos)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        // Lo que se imprime: fecha del día, indicadores de la base y lo del formulario
        $vista = app(ReportesAdminPdfService::class)->datosReporteInstitucional([...$datos, 'correo' => 'direccion@siga.test', 'fecha' => '2020-01-01']);
        $this->assertSame('12/06/2026', $vista['fecha_formateada']);
        $this->assertSame(['Acto Cívico', 'Torneo Deportivo'], $vista['actividades']);
        $this->assertNull($vista['medidas_aplicadas']);
        $html = view('admin.institucional', $vista)->render();
        foreach (['REPÚBLICA DE EL SALVADOR', 'INFORME INSTITUCIONAL', 'Educación Básica', 'Primer trimestre', '12/06/2026', 'María Fernanda López', 'direccion@siga.test',
            'CONDUCTA Y DISCIPLINA', '<li>Acto Cívico</li>', 'Feria del libro.', 'RECURSOS HUMANOS', 'Clima favorable.', 'Director(a) del Complejo Educativo Hacienda Colima Cantón el Tule', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        // Validaciones: ausentes no pasan del total de docentes del nivel (2); solo el director
        $this->como($director)->postJson('/api/reportes/institucional/pdf', [...$datos, 'docentes_ausentes' => 3])->assertStatus(422)->assertJsonValidationErrors('docentes_ausentes');
        $this->como($director)->postJson('/api/reportes/institucional/pdf', [...$datos, 'total_actividades' => ''])->assertStatus(422)->assertJsonValidationErrors('total_actividades');
        $this->como($director)->postJson('/api/reportes/institucional/pdf', [...$datos, 'actividades' => ['Excursión']])->assertStatus(422);
        $this->como($this->cuentaDocente)->postJson('/api/reportes/institucional/pdf', $datos)->assertForbidden();
    }
}
