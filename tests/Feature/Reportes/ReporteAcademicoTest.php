<?php

namespace Tests\Feature\Reportes;

use App\Models\Boleta;
use App\Models\User;
use App\Services\Admin\ReportesAdminPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte Académico del director: indicadores por nivel y período (boletas del
 * período, aprueba con 7) y PDF con dompdf.
 */
class ReporteAcademicoTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->director = $this->usuario(User::ROL_DIRECTOR);

        $periodo = $this->periodos[0]->id;
        foreach ([['Ana López', 9, 8], ['Zoila Pérez', 6, 6.5]] as [$nombre, $matematica, $lenguaje]) {
            Boleta::create(['promedio' => $matematica, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
            Boleta::create(['promedio' => $lenguaje, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->lenguaje->id, 'periodo_escolar_id' => $periodo]);
        }
    }

    public function test_indicadores_por_nivel_y_periodo(): void
    {
        $q = 'nivel='.urlencode('Educación Básica').'&periodo_escolar_id='.$this->periodos[0]->id;
        $r = $this->como($this->director)->getJson("/api/reportes/academico/indicadores?{$q}")->assertOk();

        // Pedro Pendiente no cuenta; Ana 8.5 aprueba, Zoila 6.25 reprueba
        $this->assertSame(2, $r->json('total_estudiantes'));
        $this->assertEquals(7.38, $r->json('promedio_general'));
        $this->assertEquals(50, $r->json('porcentaje_aprobacion'));
        $this->assertEquals(50, $r->json('porcentaje_reprobacion'));
        $this->assertSame('Matemática', $r->json('materia_mayor'));
        $this->assertSame('Lenguaje', $r->json('materia_menor'));

        // Otro nivel u otro período sin boletas
        $r = $this->como($this->director)->getJson('/api/reportes/academico/indicadores?nivel=Parvularia&periodo_escolar_id='.$this->periodos[0]->id)->assertOk();
        $this->assertSame(0, $r->json('total_estudiantes'));
        $this->assertNull($r->json('promedio_general'));
        $r = $this->como($this->director)->getJson('/api/reportes/academico/indicadores?nivel='.urlencode('Educación Básica').'&periodo_escolar_id='.$this->periodos[1]->id)->assertOk();
        $this->assertSame(2, $r->json('total_estudiantes'));
        $this->assertNull($r->json('materia_mayor'));

        $this->como($this->director)->getJson('/api/reportes/academico/indicadores?nivel=Media&periodo_escolar_id='.$this->periodos[0]->id)->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson("/api/reportes/academico/indicadores?{$q}")->assertForbidden();
    }

    public function test_pdf_del_reporte_academico(): void
    {
        $datos = [
            'nivel' => 'Educación Básica', 'periodo_escolar_id' => $this->periodos[0]->id, 'fecha' => '2026-06-12',
            'nombre' => 'Directora de prueba', 'cargo' => 'Directora', 'correo' => 'direccion@siga.test',
            'rendimiento_academico' => 'Desempeño favorable.', 'estudiantes_destacados' => "Ana López - Primer grado\n\n",
            'recomendaciones' => "Refuerzo en Matemática\nMás lectura", 'observaciones' => 'Sin novedades.',
        ];

        $r = $this->como($this->director)->postJson('/api/reportes/academico/pdf', $datos)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        // Lo que se imprime: datos del formulario e indicadores calculados
        $html = view('admin.academico', app(ReportesAdminPdfService::class)->datosReporteAcademico($datos))->render();
        foreach (['REPÚBLICA DE EL SALVADOR', 'CÓDIGO DE INFRAESTRUCTURA: 1689', 'INFORME ACADÉMICO INSTITUCIONAL', 'Educación Básica', 'Primer trimestre', '12/06/2026', 'Directora de prueba',
            'direccion@siga.test', '7.38', '50%', 'Matemática', '<li>Ana López - Primer grado</li>', '<li>Más lectura</li>',
            'Directora Institucional', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        $this->como($this->director)->postJson('/api/reportes/academico/pdf', [...$datos, 'correo' => 'x'])->assertStatus(422);
        $this->como($this->cuentaDocente)->postJson('/api/reportes/academico/pdf', $datos)->assertForbidden();
    }
}
