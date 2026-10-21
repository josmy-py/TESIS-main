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

    // Libreta de notas de un estudiante (docente): PDF con dompdf
    public function libretaNotasPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'grado_id' => 'required|integer',
                'seccion_id' => 'required|integer',
                'periodo_escolar_id' => 'required|integer',
                'matricula_id' => 'required|integer',
                'conducta' => 'nullable|string|max:255',
                'conducta_descripcion' => 'nullable|string|max:255',
                'observaciones' => 'nullable|string|max:500',
            ], [
                'grado_id.required' => 'Seleccione el grado.',
                'seccion_id.required' => 'Seleccione la sección.',
                'periodo_escolar_id.required' => 'Seleccione el período.',
                'matricula_id.required' => 'Seleccione el estudiante.',
            ]);

            return $this->pdfProfesor->generarLibretaNotas($request->user(), $datos)->stream('libreta_notas.pdf');
        }, 'Error al generar la libreta de notas', 'Sección no encontrada');
    }

    // Reporte de Conducta (director): total de estudiantes del año del período, para el formulario
    public function conductaIndicadores(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate(['periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id'], $this->mensajesAcademico());

            return response()->json($this->pdfAdmin->indicadoresConducta((int) $datos['periodo_escolar_id']));
        }, 'Error al calcular el total de estudiantes', 'Período no encontrado');
    }

    // Reporte de Conducta (director): PDF con dompdf (código de Josmy adaptado al
    // formulario del director: período, director y conteos; no hay estudiante ni docente)
    public function conductaDirectorPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $cantidad = 'nullable|integer|min:0|max:100000';
            $datos = $request->validate([
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
                'director' => 'required|string|max:100',
                'leves' => $cantidad,
                'moderados' => $cantidad,
                'graves' => $cantidad,
                'incidentes' => 'nullable|string|max:3000',
                'medidas' => 'nullable|array',
                'medidas.*' => ['string', Rule::in(ReportesAdminPdfService::MEDIDAS)],
                'detalle_medidas' => 'nullable|string|max:3000',
                'recomendaciones' => 'nullable|string|max:3000',
                'observaciones' => 'nullable|string|max:3000',
            ], [
                ...$this->mensajesAcademico(),
                'director.required' => 'Ingrese el nombre del director responsable.',
                'medidas.*.in' => 'La medida disciplinaria no es válida.',
            ]);

            return $this->pdfAdmin->generarReporteConducta($datos)->stream('reporte_conducta.pdf');
        }, 'Error al generar el reporte de conducta', 'Período no encontrado');
    }

    // Reporte Institucional (director): indicadores del nivel en el período para el formulario
    public function institucionalIndicadores(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'nivel' => ['required', Rule::in(ReportesAdminPdfService::NIVELES)],
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
            ], $this->mensajesAcademico());

            return response()->json($this->pdfAdmin->indicadoresInstitucionales($datos['nivel'], (int) $datos['periodo_escolar_id']));
        }, 'Error al calcular los indicadores institucionales', 'Período no encontrado');
    }

    // Reporte Institucional (director): PDF con dompdf. Código de Josmy
    // (generarReporteAdministrativo) ajustado: nivel y período en lugar de
    // especialidad, la fecha la pone el backend y el correo es el de la cuenta.
    public function institucionalPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $cantidad = 'nullable|integer|min:0|max:100000';
            $datos = $request->validate([
                'nivel' => ['required', Rule::in(ReportesAdminPdfService::NIVELES)],
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
                'nombre' => 'required|string|max:100',
                'cargo' => ['required', Rule::in(['Director', 'Directora'])],
                'descripcion_calificaciones' => 'nullable|string|max:3000',
                'incidentes' => $cantidad,
                'reportes_leves' => $cantidad,
                'reportes_graves' => $cantidad,
                'medidas_aplicadas' => $cantidad,
                'descripcion_casos' => 'nullable|string|max:3000',
                'total_actividades' => 'required|integer|min:0|max:1000',
                'total_proyectos' => 'required|integer|min:0|max:1000',
                'actividades' => 'nullable|array',
                'actividades.*' => ['string', Rule::in(ReportesAdminPdfService::ACTIVIDADES)],
                'descripcion_actividades' => 'nullable|string|max:3000',
                'docentes_ausentes' => 'required|integer|min:0|max:100000',
                'observaciones' => 'nullable|string|max:3000',
            ], [
                ...$this->mensajesAcademico(),
                'nombre.required' => 'Ingrese el nombre del director.',
                'total_actividades.required' => 'Ingrese el total de actividades.',
                'total_proyectos.required' => 'Ingrese el total de proyectos.',
                'docentes_ausentes.required' => 'Ingrese los docentes ausentes.',
                'actividades.*.in' => 'La actividad no es válida.',
            ]);
            $datos['correo'] = $request->user()->email;

            return $this->pdfAdmin->generarReporteInstitucional($datos)->stream('reporte_institucional.pdf');
        }, 'Error al generar el reporte institucional', 'Período no encontrado');
    }

    // Reporte Docente (director): total de docentes y activos del año del período, para el formulario
    public function docenteIndicadores(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate(['periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id'], $this->mensajesAcademico());

            return response()->json($this->pdfAdmin->indicadoresDocentes((int) $datos['periodo_escolar_id']));
        }, 'Error al calcular los indicadores de docentes', 'Período no encontrado');
    }

    // Reporte Docente (director): PDF con dompdf
    public function docentePdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $cantidad = 'required|integer|min:0|max:100000';
            $datos = $request->validate([
                'periodo_escolar_id' => 'required|integer|exists:periodo_escolares,id',
                'director' => 'required|string|max:100',
                'cargo' => ['required', Rule::in(['Director', 'Directora'])],
                'docentes_ausentes' => $cantidad,
                'docentes_incapacitados' => $cantidad,
                'asistencia_promedio' => 'nullable|numeric|min:0|max:100',
                'docentes_evaluados' => $cantidad,
                'promedio_evaluacion' => 'nullable|numeric|min:0|max:10',
                'descripcion_general' => 'nullable|string|max:3000',
                'capacitaciones_realizadas' => 'nullable|integer|min:0|max:1000',
                'docentes_participantes' => 'nullable|integer|min:0|max:100000',
                'detalles_capacitaciones' => 'nullable|string|max:3000',
                'reconocimientos' => 'nullable|string|max:3000',
                'observaciones' => 'nullable|string|max:3000',
            ], [
                ...$this->mensajesAcademico(),
                'director.required' => 'Ingrese el nombre del director.',
                'docentes_ausentes.required' => 'Ingrese los docentes ausentes.',
                'docentes_incapacitados.required' => 'Ingrese las incapacidades.',
                'docentes_evaluados.required' => 'Ingrese los docentes evaluados.',
            ]);
            $datos['correo_usuario'] = $request->user()->email;

            return $this->pdfAdmin->generarReporteDocente($datos)->stream('reporte_docente.pdf');
        }, 'Error al generar el reporte docente', 'Período no encontrado');
    }

    // Reporte de Conducta de un estudiante (docente): PDF con dompdf
    public function conductaPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'grado_id' => 'required|integer',
                'seccion_id' => 'required|integer',
                'periodo_escolar_id' => 'required|integer',
                'matricula_id' => 'required|integer',
                'conducta' => 'required|string|max:80',
                'descripcion_hechos' => 'required|string|max:1000',
                'acciones_tomadas' => 'required|string|max:500',
                'compromiso_mejora' => 'required|string|max:1000',
            ], [
                'grado_id.required' => 'Seleccione el grado.',
                'seccion_id.required' => 'Seleccione la sección.',
                'periodo_escolar_id.required' => 'Seleccione el período.',
                'matricula_id.required' => 'Seleccione el estudiante.',
                'conducta.required' => 'Seleccione el tipo de conducta.',
                'descripcion_hechos.required' => 'Describa los hechos.',
                'acciones_tomadas.required' => 'Indique las acciones tomadas.',
                'acciones_tomadas.max' => 'Las acciones tomadas no pueden pasar de 500 caracteres.',
                'compromiso_mejora.required' => 'Escriba el compromiso de mejora.',
            ]);

            return $this->pdfProfesor->generarConducta($request->user(), $datos)->stream('reporte_conducta.pdf');
        }, 'Error al generar el reporte de conducta', 'Sección no encontrada');
    }

    // Reporte de Rendimiento de una materia (docente): PDF con dompdf
    public function rendimientoPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'grado_id' => 'required|integer',
                'seccion_id' => 'required|integer',
                'periodo_escolar_id' => 'required|integer',
                'asignacion_id' => 'required|integer',
                'profesor' => 'required|string|max:150',
                'observaciones' => 'nullable|string|max:500',
            ], [
                'grado_id.required' => 'Seleccione el grado.',
                'seccion_id.required' => 'Seleccione la sección.',
                'periodo_escolar_id.required' => 'Seleccione el período.',
                'asignacion_id.required' => 'Seleccione la asignatura.',
                'profesor.required' => 'Ingrese el nombre del profesor.',
            ]);

            return $this->pdfProfesor->generarRendimiento($request->user(), $datos)->stream('reporte_rendimiento.pdf');
        }, 'Error al generar el reporte de rendimiento');
    }

    // Informe Profesor (docente): PDF con dompdf
    public function informeProfesorPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'grado_id' => 'required|integer',
                'seccion_id' => 'required|integer',
                'periodo_escolar_id' => 'required|integer',
                'profesor' => 'required|string|max:150',
                'asunto' => 'required|string|max:255',
                'desarrollo' => 'required|string|max:5000',
                'observaciones' => 'nullable|string|max:3000',
            ], [
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
            'nombre.required' => 'Ingrese el nombre del responsable.',
            'cargo.in' => 'El cargo no es válido.',
            'correo.required' => 'Ingrese el correo electrónico.',
            'correo.email' => 'El correo electrónico no es válido.',
        ];
    }
}
