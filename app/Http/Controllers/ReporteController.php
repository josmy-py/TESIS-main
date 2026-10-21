<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\Admin\ReportesAdminPdfService;
use App\Services\Profesor\ReportesProfesorPdfService;
use App\Services\ReporteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M12 Reportes de notas y de conducta por sección y periodo (director: todas
 * las secciones; docente: las suyas).
 */
class ReporteController extends Controller
{
    use RespondeErrores;

    public function __construct(private ReporteService $reportes, private ReportesAdminPdfService $pdfAdmin, private ReportesProfesorPdfService $pdfProfesor)
    {
    }

    // Reporte Académico (director): indicadores del nivel en el período para el formulario
    public function academicoIndicadores(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'nivel' => ['required', Rule::in(ReportesAdminPdfService::NIVELES)],
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
            ], $this->mensajesAcademico());

            return response()->json($this->pdfAdmin->indicadoresAcademicos($datos['nivel'], (int) $datos['periodo_escolar_id']));
        }, 'Error al calcular los indicadores académicos', 'Período no encontrado');
    }

    // Reporte Académico (director): PDF con dompdf
    public function academicoPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'nivel' => ['required', Rule::in(ReportesAdminPdfService::NIVELES)],
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
                'fecha' => 'required|date',
                'nombre' => 'required|string|max:150',
                'cargo' => ['required', Rule::in(['Director', 'Directora'])],
                'correo' => 'required|email|max:150',
                'rendimiento_academico' => 'nullable|string|max:3000',
                'estudiantes_destacados' => 'nullable|string|max:3000',
                'recomendaciones' => 'nullable|string|max:3000',
                'observaciones' => 'nullable|string|max:3000',
            ], $this->mensajesAcademico());

            return $this->pdfAdmin->generarReporteAcademico($datos)->stream('reporte_academico.pdf');
        }, 'Error al generar el reporte académico', 'Período no encontrado');
    }

    public function notas(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $this->validar($request);
            $seccion = $this->reportes->seccion($request->user(), $datos['seccion_id']);

            return response()->json($this->reportes->notas($request->user(), $seccion, $datos['periodo_escolar_id']));
        }, 'Error al generar el reporte de notas', 'Sección no encontrada');
    }

    public function conducta(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $this->validar($request);
            $seccion = $this->reportes->seccion($request->user(), $datos['seccion_id']);

            return response()->json($this->reportes->conducta($seccion, $datos['periodo_escolar_id']));
        }, 'Error al generar el reporte de conducta', 'Sección no encontrada');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'seccion_id' => 'required|integer',
            'periodo_escolar_id' => 'required|integer',
        ], [
            'seccion_id.required' => 'Seleccione la sección.',
            'periodo_escolar_id.required' => 'Seleccione el periodo.',
        ]);
    }

    // Informe Profesor (docente): PDF con dompdf
    public function informeProfesorPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'fecha' => 'required|date',
                'grado_id' => 'required|integer',
                'seccion_id' => 'required|integer',
                'periodo_escolar_id' => 'required|integer',
                'profesor' => 'required|string|max:150',
                'asunto' => 'required|string|max:255',
                'desarrollo' => 'required|string|max:5000',
                'observaciones' => 'nullable|string|max:3000',
            ], [
                'fecha.required' => 'Ingrese la fecha.',
                'grado_id.required' => 'Seleccione el grado.',
                'seccion_id.required' => 'Seleccione la sección.',
                'periodo_escolar_id.required' => 'Seleccione el período.',
                'profesor.required' => 'Ingrese el nombre del profesor.',
                'asunto.required' => 'Ingrese el asunto.',
                'desarrollo.required' => 'Describa el desarrollo del informe.',
            ]);

            return $this->pdfProfesor->generarInforme($request->user(), $datos)->stream('informe_profesor.pdf');
        }, 'Error al generar el informe del profesor', 'Sección no encontrada');
    }

    private function mensajesAcademico(): array
    {
        return [
            'nivel.required' => 'Seleccione el nivel académico.',
            'nivel.in' => 'El nivel académico no es válido.',
            'periodo_escolar_id.required' => 'Seleccione el período.',
            'periodo_escolar_id.exists' => 'El período seleccionado no existe.',
            'fecha.required' => 'Ingrese la fecha.',
            'nombre.required' => 'Ingrese el nombre del responsable.',
            'cargo.in' => 'El cargo no es válido.',
            'correo.required' => 'Ingrese el correo electrónico.',
            'correo.email' => 'El correo electrónico no es válido.',
        ];
    }
}
