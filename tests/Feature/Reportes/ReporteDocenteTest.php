<?php

namespace Tests\Feature\Reportes;

use App\Models\User;
use App\Services\Admin\ReportesAdminPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte Docente del director: total de docentes y activos de la base, lo
 * demás del formulario, y PDF con dompdf con la fecha del día.
 */
class ReporteDocenteTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->docenteNuevo(3); // registrado, sin carga académica
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        $this->travelTo(now()->parse('2026-06-12 15:00:00', 'UTC'));
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'periodo_escolar_id' => $this->periodos[0]->id, 'director' => 'Directora de prueba', 'cargo' => 'Directora',
            'docentes_ausentes' => 1, 'docentes_incapacitados' => 0, 'asistencia_promedio' => 96, 'docentes_evaluados' => 2,
            'promedio_evaluacion' => 9.2, 'descripcion_general' => 'Desempeño satisfactorio.', 'capacitaciones_realizadas' => 2,
            'docentes_participantes' => 3, 'detalles_capacitaciones' => 'Metodologías activas.',
            'reconocimientos' => "Docente destacado\n\nInnovación pedagógica", 'observaciones' => 'Sin novedades.',
        ], $cambios);
    }

    public function test_indicadores_de_la_base(): void
    {
        $r = $this->como($this->director)->getJson('/api/reportes/docente/indicadores?periodo_escolar_id='.$this->periodos[0]->id)->assertOk();
        $this->assertSame(3, $r->json('total_docentes'));
        $this->assertSame(2, $r->json('docentes_activos'));

        $this->como($this->director)->getJson('/api/reportes/docente/indicadores?periodo_escolar_id=999')->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson('/api/reportes/docente/indicadores?periodo_escolar_id='.$this->periodos[0]->id)->assertForbidden();
    }

    public function test_pdf_del_reporte_docente(): void
    {
        $r = $this->como($this->director)->postJson('/api/reportes/docente/pdf', $this->datos())->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $datos = app(ReportesAdminPdfService::class)->datosReporteDocente($this->datos(['correo_usuario' => 'direccion@siga.test']));
        $html = view('admin.docente', $datos)->render();
        foreach (['CÓDIGO DE INFRAESTRUCTURA: 1689', 'REPORTE DEL PERSONAL DOCENTE', 'Directora de prueba', 'direccion@siga.test', 'Primer trimestre',
            '12/06/2026', '<td>Total de Docentes</td><td>3</td>', '<td>Docentes Activos</td><td>2</td>', '96%', '9.2', 'Desempeño satisfactorio.',
            'Metodologías activas.', '<li>Docente destacado</li>', '<li>Innovación pedagógica</li>', 'Sin novedades.',
            'Director(a) del Complejo Educativo Hacienda Colima Cantón el Tule', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
    }

    public function test_validaciones(): void
    {
        // Ninguna cantidad pasa del total de docentes registrados (3)
        $this->como($this->director)->postJson('/api/reportes/docente/pdf', $this->datos(['docentes_ausentes' => 4, 'docentes_participantes' => 5]))
            ->assertStatus(422)->assertJsonValidationErrors(['docentes_ausentes', 'docentes_participantes']);
        $this->como($this->director)->postJson('/api/reportes/docente/pdf', $this->datos(['docentes_evaluados' => null, 'cargo' => 'Rector']))
            ->assertStatus(422)->assertJsonValidationErrors(['docentes_evaluados', 'cargo']);
        $this->como($this->director)->postJson('/api/reportes/docente/pdf', $this->datos(['asistencia_promedio' => 120, 'promedio_evaluacion' => 11]))
            ->assertStatus(422)->assertJsonValidationErrors(['asistencia_promedio', 'promedio_evaluacion']);
        $this->como($this->cuentaDocente)->postJson('/api/reportes/docente/pdf', $this->datos())->assertForbidden();
    }
}
