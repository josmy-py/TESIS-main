<?php

namespace Tests\Feature\Reportes;

use App\Services\Admin\ReportesAdminPdfService;
use App\Services\Profesor\ReportesProfesorPdfService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Vistas PDF (dompdf) de los reportes del director y del docente: cada una
 * existe y lleva el encabezado institucional.
 */
class ReportesPdfTest extends TestCase
{
    // Todos los reportes ya tienen diseño y su propia prueba con datos (PDF y textos);
    // aquí se comprueba que cada vista existe y que el encabezado común se dibuja.
    public function test_cada_reporte_tiene_su_vista_y_el_encabezado(): void
    {
        foreach ([...array_values(ReportesAdminPdfService::VISTAS), ...array_values(ReportesProfesorPdfService::VISTAS)] as $vista) {
            $this->assertTrue(view()->exists($vista), $vista);
            $this->assertStringContainsString("@include('encabezado_reporte')", file_get_contents(view($vista)->getPath()), $vista);
        }

        $html = view('encabezado_reporte')->render();
        $this->assertStringContainsString('COMPLEJO EDUCATIVO HACIENDA COLIMA', $html);
        $this->assertStringContainsString('DIRECCIÓN DEPARTAMENTAL DE EDUCACIÓN', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_reporte_desconocido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ReportesProfesorPdfService::class)->generar('inexistente');
    }
}
