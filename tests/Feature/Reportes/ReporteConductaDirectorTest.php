<?php

namespace Tests\Feature\Reportes;

use App\Models\AnioLectivo;
use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\Admin\ReportesAdminPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte de Conducta del director: el formulario toma de la base el total de
 * estudiantes con matrícula aprobada en el año del período.
 */
class ReporteConductaDirectorTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    public function test_total_de_estudiantes_del_anio_del_periodo(): void
    {
        $this->prepararSeccion();
        $director = $this->usuario(User::ROL_DIRECTOR);
        $url = '/api/reportes/conducta/indicadores?periodo_escolar_id=';

        // Ana y Zoila aprobadas; Pedro pendiente no cuenta
        $this->como($director)->getJson($url.$this->periodos[0]->id)->assertOk()->assertExactJson(['total_estudiantes' => 2]);

        $otro = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $periodo = PeriodoEscolar::create(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $otro->id]);
        $this->como($director)->getJson($url.$periodo->id)->assertOk()->assertExactJson(['total_estudiantes' => 0]);

        $this->como($director)->getJson($url.'999')->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson($url.$this->periodos[0]->id)->assertForbidden();
    }

    public function test_pdf_del_reporte_de_conducta(): void
    {
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-06-14 15:00:00', 'UTC'));
        $director = $this->usuario(User::ROL_DIRECTOR);
        $datos = [
            'periodo_escolar_id' => $this->periodos[0]->id, 'director' => 'Juan Pérez',
            'leves' => 10, 'moderados' => 5, 'graves' => 3,
            'incidentes' => 'Durante el período se registraron incidentes.',
            'medidas' => ['Citación a Padres', 'Amonestación Verbal'], 'detalle_medidas' => "Seguimiento por orientación escolar\n\n",
            'recomendaciones' => "Continuar fortaleciendo la convivencia\nDar seguimiento a casos", 'observaciones' => 'Mejora general.',
        ];

        $r = $this->como($director)->postJson('/api/reportes/conducta/pdf', $datos)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        // Lo que se imprime: fecha del día, total de la base e incidentes = 10 + 5 + 3
        $vista = app(ReportesAdminPdfService::class)->datosReporteConducta($datos);
        $this->assertSame(2, $vista['total_estudiantes']);
        $this->assertSame(18, $vista['incidentes_registrados']);
        $this->assertSame(['Amonestación Verbal', 'Citación a Padres', 'Seguimiento por orientación escolar'], $vista['medidas']);
        $html = view('admin.conducta', $vista)->render();
        foreach (['REPÚBLICA DE EL SALVADOR', 'INFORME INSTITUCIONAL DE CONDUCTA Y CONVIVENCIA ESCOLAR', '14/06/2026', 'Primer trimestre', 'Juan Pérez',
            '<li>Dar seguimiento a casos</li>', 'Mejora general.', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        // Validaciones, la fecha no se recibe y solo el director
        $this->como($director)->postJson('/api/reportes/conducta/pdf', [...$datos, 'director' => ''])->assertStatus(422)->assertJsonValidationErrors('director');
        $this->como($director)->postJson('/api/reportes/conducta/pdf', [...$datos, 'medidas' => ['Expulsión']])->assertStatus(422);
        $this->como($director)->postJson('/api/reportes/conducta/pdf', [...$datos, 'graves' => -1])->assertStatus(422);
        $this->assertSame('14/06/2026', app(ReportesAdminPdfService::class)->datosReporteConducta([...$datos, 'fecha' => '2020-01-01'])['fecha_formateada']);
        $this->como($this->cuentaDocente)->postJson('/api/reportes/conducta/pdf', $datos)->assertForbidden();
    }
}
