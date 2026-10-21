<?php

namespace Tests\Feature\Reportes;

use App\Services\Admin\ReportesAdminPdfService;
use App\Services\Profesor\ReportesProfesorPdfService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Vistas PDF (dompdf) de los reportes del director y del docente: cada una
 * genera un PDF con el encabezado institucional.
 */
class ReportesPdfTest extends TestCase
{
    public function test_cada_reporte_genera_un_pdf_con_el_encabezado(): void
    {
        $servicios = [
            [app(ReportesAdminPdfService::class), ReportesAdminPdfService::VISTAS],
            [app(ReportesProfesorPdfService::class), ReportesProfesorPdfService::VISTAS],
        ];

        foreach ($servicios as [$servicio, $vistas]) {
            // El académico y el informe de profesor necesitan datos: tienen su propia prueba
            foreach (array_diff(array_keys($vistas), ['academico', 'informe_profesor']) as $reporte) {
                $html = view($vistas[$reporte])->render();
                $this->assertStringContainsString('COMPLEJO EDUCATIVO HACIENDA COLIMA', $html, $reporte);
                $this->assertStringContainsString('DIRECCIÓN DEPARTAMENTAL DE EDUCACIÓN', $html, $reporte);
                $this->assertStringContainsString('data:image/png;base64,', $html, $reporte);

                $this->assertStringStartsWith('%PDF', $servicio->generar($reporte)->output(), $reporte);
            }
        }
    }

    public function test_reporte_desconocido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ReportesProfesorPdfService::class)->generar('inexistente');
    }
}
