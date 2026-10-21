<?php

namespace App\Services\Admin;

use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Services\PromocionService;
use App\Support\CatalogoGrados;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PDF de los reportes del portal director (dompdf). Cada reporte tiene su vista
 * en resources/views/admin con el encabezado institucional.
 *
 * Reporte Académico (código de Josmy del 20/10/2026, ajustado al formulario y a
 * la base de datos): se filtra por Nivel Académico (Parvularia, Educación Básica
 * o Bachillerato según el grado de la sección) y por el período elegido. Los
 * indicadores salen de las boletas del período: el promedio de cada estudiante
 * es el de sus boletas y aprueba con PromocionService::NOTA_MINIMA.
 */
class ReportesAdminPdfService
{
    public const VISTAS = [
        'academico' => 'admin.academico',
        'conducta' => 'admin.conducta',
        'docente' => 'admin.docente',
        'institucional' => 'admin.institucional',
    ];

    public const NIVELES = ['Parvularia', 'Educación Básica', 'Bachillerato'];

    public function generar(string $reporte, array $datos = []): \Barryvdh\DomPDF\PDF
    {
        $vista = self::VISTAS[$reporte] ?? throw new InvalidArgumentException("Reporte desconocido: {$reporte}");

        return Pdf::loadView($vista, $datos)->setPaper('letter', 'portrait');
    }

    /** Indicadores y materias del nivel en el período (los muestra el formulario y los usa el PDF). */
    public function indicadoresAcademicos(string $nivel, int $periodoId): array
    {
        $periodo = PeriodoEscolar::findOrFail($periodoId);
        $grados = Grado::all()->filter(fn (Grado $g) => CatalogoGrados::nivel($g->orden) === $nivel)->pluck('id');

        $matriculas = DB::table('matriculas')
            ->join('secciones', 'secciones.id', '=', 'matriculas.seccion_id')
            ->whereIn('secciones.grado_id', $grados)
            ->where('secciones.anio_lectivo_id', $periodo->anio_lectivo_id)
            ->where('matriculas.estado', 'aprobado');

        $boletas = (clone $matriculas)
            ->join('boletas', 'boletas.matricula_id', '=', 'matriculas.id')
            ->where('boletas.periodo_escolar_id', $periodo->id);

        // Promedio de cada estudiante en el período (sus boletas)
        $promedios = (clone $boletas)
            ->groupBy('matriculas.id')
            ->selectRaw('AVG(boletas.promedio) as promedio')
            ->pluck('promedio')
            ->map(fn ($p) => (float) $p);

        $evaluados = $promedios->count();
        $aprobados = $promedios->filter(fn ($p) => $p >= PromocionService::NOTA_MINIMA)->count();

        $materias = (clone $boletas)
            ->join('asignaciones', 'asignaciones.id', '=', 'boletas.asignacion_id')
            ->join('plan_asignaturas', 'plan_asignaturas.id', '=', 'asignaciones.plan_asignatura_id')
            ->join('asignaturas', 'asignaturas.id', '=', 'plan_asignaturas.asignatura_id')
            ->groupBy('asignaturas.nombre')
            ->select('asignaturas.nombre')
            ->selectRaw('AVG(boletas.promedio) as promedio')
            ->get()
            ->sortByDesc(fn ($m) => (float) $m->promedio)
            ->values();

        return [
            'total_estudiantes' => (clone $matriculas)->count('matriculas.id'),
            'estudiantes_evaluados' => $evaluados,
            'promedio_general' => $evaluados > 0 ? round($promedios->avg(), 2) : null,
            'porcentaje_aprobacion' => $evaluados > 0 ? round($aprobados / $evaluados * 100, 2) : null,
            'porcentaje_reprobacion' => $evaluados > 0 ? round(($evaluados - $aprobados) / $evaluados * 100, 2) : null,
            'materia_mayor' => $materias->first()?->nombre,
            'materia_menor' => $materias->count() > 1 ? $materias->last()->nombre : null,
        ];
    }

    public function generarReporteAcademico(array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('academico', $this->datosReporteAcademico($datos));
    }

    /** Datos de la vista admin.academico: lo llenado en el formulario más los indicadores calculados. */
    public function datosReporteAcademico(array $datos): array
    {
        $periodo = PeriodoEscolar::findOrFail($datos['periodo_escolar_id']);
        $lista = fn (?string $texto) => collect(preg_split('/\R/', (string) $texto))->map(fn ($l) => trim($l))->filter()->values()->all();

        return [
            ...$datos,
            ...$this->indicadoresAcademicos($datos['nivel'], $periodo->id),
            'periodo' => $periodo->nombre,
            'fecha_formateada' => date('d/m/Y', strtotime($datos['fecha'])),
            'rendimiento_academico' => $datos['rendimiento_academico'] ?? '',
            'estudiantes_destacados' => $lista($datos['estudiantes_destacados'] ?? null),
            'recomendaciones' => $lista($datos['recomendaciones'] ?? null),
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }
}
