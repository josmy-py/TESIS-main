<?php

namespace App\Services\Admin;

use App\Models\Asignacion;
use App\Models\Grado;
use App\Models\PeriodoEscolar;
use App\Models\Profesor;
use App\Services\PromocionService;
use App\Services\Profesor\ReportesProfesorPdfService;
use App\Support\CatalogoGrados;
use App\Support\FechaReporte;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
 *
 * Reporte Docente (código de Josmy del 21/10/2026, ajustado): Total de Docentes
 * (profesores registrados) y Docentes Activos (con carga académica en el año del
 * período) salen de la base; asistencia, evaluación y capacitaciones no existen
 * en el sistema y se escriben en el formulario. Todos los PDF llevan la fecha del
 * día en que se generan (FechaReporte).
 *
 * Reporte de Conducta (formulario, código y diseño de Josmy del 21/10/2026):
 * Total de Estudiantes (matrículas aprobadas del año del período) sale de la base;
 * la clasificación, incidentes y medidas no existen en el sistema y se escriben
 * en el formulario. Incidentes Registrados = leves + moderados + graves.
 *
 * Reporte Institucional (formulario de Josmy del 21/10/2026): por Nivel Académico
 * y período salen de la base el total de estudiantes (matrícula total), el
 * promedio general y los destacados (promedio >= 9) y reprobados (< 7) según las
 * boletas del período, los nuevos ingresos (matrículas "Nuevo ingreso") y el
 * total de docentes con carga académica en el nivel. Lo demás se escribe. El PDF
 * (código de Josmy generarReporteAdministrativo, ajustado) lleva las mismas
 * secciones del formulario.
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

    /** Medidas disciplinarias del formulario de conducta (casillas). */
    public const MEDIDAS = ['Amonestación Verbal', 'Amonestación Escrita', 'Citación a Padres', 'Suspensión'];

    /** Actividades institucionales del formulario institucional (casillas). */
    public const ACTIVIDADES = ['Acto Cívico', 'Feria Científica', 'Torneo Deportivo', 'Actividad Cultural'];

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
            ->join('asignaturas', 'asignaturas.id', '=', 'asignaciones.asignatura_id')
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
            'fecha_formateada' => FechaReporte::hoy(),
            'rendimiento_academico' => $datos['rendimiento_academico'] ?? '',
            'estudiantes_destacados' => $lista($datos['estudiantes_destacados'] ?? null),
            'recomendaciones' => $lista($datos['recomendaciones'] ?? null),
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }

    /** Total de docentes y los que tienen carga académica en el año del período (los muestra el formulario). */
    public function indicadoresDocentes(int $periodoId): array
    {
        $periodo = PeriodoEscolar::findOrFail($periodoId);

        return [
            'total_docentes' => Profesor::count(),
            'docentes_activos' => Asignacion::whereHas('seccion', fn ($q) => $q->where('anio_lectivo_id', $periodo->anio_lectivo_id))
                ->distinct()->count('profesor_id'),
        ];
    }

    public function generarReporteDocente(array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('docente', $this->datosReporteDocente($datos));
    }

    /** Datos de la vista admin.docente: indicadores de la base más lo llenado en el formulario. */
    public function datosReporteDocente(array $datos): array
    {
        $periodo = PeriodoEscolar::findOrFail($datos['periodo_escolar_id']);
        $indicadores = $this->indicadoresDocentes($periodo->id);

        // Ninguna cantidad de docentes puede pasar del total registrado
        $mayores = collect(['docentes_ausentes', 'docentes_incapacitados', 'docentes_evaluados', 'docentes_participantes'])
            ->filter(fn ($campo) => (int) ($datos[$campo] ?? 0) > $indicadores['total_docentes'])
            ->mapWithKeys(fn ($campo) => [$campo => "No puede ser mayor que el total de docentes ({$indicadores['total_docentes']})."]);
        if ($mayores->isNotEmpty()) {
            throw ValidationException::withMessages($mayores->all());
        }

        return [
            ...$datos,
            ...$indicadores,
            'periodo' => $periodo->nombre,
            'fecha_formateada' => FechaReporte::hoy(),
            'asistencia_promedio' => $datos['asistencia_promedio'] ?? null,
            'promedio_evaluacion' => $datos['promedio_evaluacion'] ?? null,
            'capacitaciones_realizadas' => $datos['capacitaciones_realizadas'] ?? null,
            'docentes_participantes' => $datos['docentes_participantes'] ?? null,
            'descripcion_general' => $datos['descripcion_general'] ?? '',
            'detalles_capacitaciones' => $datos['detalles_capacitaciones'] ?? '',
            'reconocimientos' => collect(preg_split('/\R/', (string) ($datos['reconocimientos'] ?? '')))->map(fn ($l) => trim($l))->filter()->values()->all(),
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }

    /** Total de estudiantes con matrícula aprobada en el año del período (lo muestra el formulario de conducta). */
    public function indicadoresConducta(int $periodoId): array
    {
        $periodo = PeriodoEscolar::findOrFail($periodoId);

        return [
            'total_estudiantes' => DB::table('matriculas')
                ->where('anio_lectivo_id', $periodo->anio_lectivo_id)
                ->where('estado', 'aprobado')
                ->count(),
        ];
    }

    public function generarReporteConducta(array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('conducta', $this->datosReporteConducta($datos));
    }

    /** Datos de la vista admin.conducta: total de estudiantes de la base más lo llenado en el formulario. */
    public function datosReporteConducta(array $datos): array
    {
        $periodo = PeriodoEscolar::findOrFail($datos['periodo_escolar_id']);
        $lineas = fn (?string $texto) => collect(preg_split('/\R/', (string) $texto))->map(fn ($l) => trim($l))->filter()->values()->all();
        $cantidad = fn (string $campo) => (int) ($datos[$campo] ?? 0);

        return [
            ...$this->indicadoresConducta($periodo->id),
            'periodo' => $periodo->nombre,
            'fecha_formateada' => FechaReporte::hoy(),
            'director' => $datos['director'],
            'leves' => $cantidad('leves'),
            'moderados' => $cantidad('moderados'),
            'graves' => $cantidad('graves'),
            'incidentes_registrados' => $cantidad('leves') + $cantidad('moderados') + $cantidad('graves'),
            'incidentes' => $datos['incidentes'] ?? '',
            // Casillas marcadas (en el orden del formulario) y después cada línea del detalle
            'medidas' => [
                ...array_values(array_intersect(self::MEDIDAS, $datos['medidas'] ?? [])),
                ...$lineas($datos['detalle_medidas'] ?? null),
            ],
            'observaciones' => $datos['observaciones'] ?? '',
            'recomendaciones' => $lineas($datos['recomendaciones'] ?? null),
        ];
    }

    /** Indicadores del nivel en el período que muestra el formulario institucional. */
    public function indicadoresInstitucionales(string $nivel, int $periodoId): array
    {
        $periodo = PeriodoEscolar::findOrFail($periodoId);
        $grados = Grado::all()->filter(fn (Grado $g) => CatalogoGrados::nivel($g->orden) === $nivel)->pluck('id');

        $matriculas = DB::table('matriculas')
            ->join('secciones', 'secciones.id', '=', 'matriculas.seccion_id')
            ->whereIn('secciones.grado_id', $grados)
            ->where('secciones.anio_lectivo_id', $periodo->anio_lectivo_id)
            ->where('matriculas.estado', 'aprobado');

        // Promedio de cada estudiante en el período (sus boletas)
        $promedios = (clone $matriculas)
            ->join('boletas', 'boletas.matricula_id', '=', 'matriculas.id')
            ->where('boletas.periodo_escolar_id', $periodo->id)
            ->groupBy('matriculas.id')
            ->selectRaw('AVG(boletas.promedio) as promedio')
            ->pluck('promedio')
            ->map(fn ($p) => (float) $p);

        return [
            'total_estudiantes' => (clone $matriculas)->count('matriculas.id'),
            'nuevos_ingresos' => (clone $matriculas)->where('matriculas.ingreso', 'Nuevo ingreso')->count('matriculas.id'),
            'estudiantes_evaluados' => $promedios->count(),
            'promedio_general' => $promedios->isNotEmpty() ? round($promedios->avg(), 2) : null,
            'estudiantes_destacados' => $promedios->filter(fn ($p) => $p >= ReportesProfesorPdfService::NOTA_DESTACADO)->count(),
            'estudiantes_reprobados' => $promedios->filter(fn ($p) => $p < PromocionService::NOTA_MINIMA)->count(),
            'total_docentes' => Asignacion::whereHas('seccion', fn ($q) => $q->where('anio_lectivo_id', $periodo->anio_lectivo_id)->whereIn('grado_id', $grados))
                ->distinct()->count('profesor_id'),
        ];
    }

    public function generarReporteInstitucional(array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('institucional', $this->datosReporteInstitucional($datos));
    }

    /** Datos de la vista admin.institucional: indicadores de la base más lo llenado en el formulario. */
    public function datosReporteInstitucional(array $datos): array
    {
        $periodo = PeriodoEscolar::findOrFail($datos['periodo_escolar_id']);
        $indicadores = $this->indicadoresInstitucionales($datos['nivel'], $periodo->id);

        if ((int) $datos['docentes_ausentes'] > $indicadores['total_docentes']) {
            throw ValidationException::withMessages(['docentes_ausentes' => "No puede ser mayor que el total de docentes ({$indicadores['total_docentes']})."]);
        }
        $nulo = fn (string $campo) => isset($datos[$campo]) && $datos[$campo] !== '' ? $datos[$campo] : null;

        return [
            ...$indicadores,
            'nivel' => $datos['nivel'],
            'periodo' => $periodo->nombre,
            'fecha_formateada' => FechaReporte::hoy(),
            'nombre' => $datos['nombre'],
            'cargo' => $datos['cargo'],
            'correo' => $datos['correo'],
            'descripcion_calificaciones' => $datos['descripcion_calificaciones'] ?? '',
            'incidentes' => $nulo('incidentes'),
            'reportes_leves' => $nulo('reportes_leves'),
            'reportes_graves' => $nulo('reportes_graves'),
            'medidas_aplicadas' => $nulo('medidas_aplicadas'),
            'descripcion_casos' => $datos['descripcion_casos'] ?? '',
            'total_actividades' => (int) $datos['total_actividades'],
            'total_proyectos' => (int) $datos['total_proyectos'],
            'actividades' => array_values(array_intersect(self::ACTIVIDADES, $datos['actividades'] ?? [])),
            'descripcion_actividades' => $datos['descripcion_actividades'] ?? '',
            'docentes_ausentes' => (int) $datos['docentes_ausentes'],
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }
}
