<?php

namespace App\Services\Profesor;

use App\Models\Asignacion;
use App\Models\Boleta;
use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\ConductaService;
use App\Services\ReporteService;
use App\Support\FechaReporte;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * PDF de los reportes del portal docente (dompdf). Cada reporte tiene su vista
 * en resources/views/profesor con el encabezado institucional común.
 *
 * Informe Profesor (código de Josmy del 20/10/2026, ajustado a los datos del
 * sistema): grado, sección y período salen de la base, a partir de una sección
 * donde el docente enseña o es guía y de un período de su año lectivo.
 *
 * Libreta de notas (código de Josmy del 21/10/2026, ajustado): las materias y
 * promedios salen del reporte de notas (ReporteService: el guía ve todas las
 * materias, los demás docentes solo las suyas) y el promedio general es el del
 * período, como en el formulario.
 *
 * Reporte de Rendimiento (código de Josmy del 21/10/2026, ajustado): una materia
 * propia del docente en una sección; alumnos con matrícula aprobada y el promedio
 * de su boleta del período (sin boleta no cuenta para el promedio).
 */
class ReportesProfesorPdfService
{
    public const NOTA_DESTACADO = 9;

    public const VISTAS = [
        'boleta' => 'profesor.boleta',
        'conducta' => 'profesor.conducta',
        'informe_profesor' => 'profesor.informe_profesor',
        'rendimiento' => 'profesor.rendimiento',
    ];

    public function __construct(private ConductaService $conducta, private ReporteService $reportes)
    {
    }

    public function generar(string $reporte, array $datos = []): \Barryvdh\DomPDF\PDF
    {
        $vista = self::VISTAS[$reporte] ?? throw new InvalidArgumentException("Reporte desconocido: {$reporte}");

        return Pdf::loadView($vista, $datos)->setPaper('letter', 'portrait');
    }

    public function generarInforme(User $docente, array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('informe_profesor', $this->datosInforme($docente, $datos));
    }

    /** Datos de la vista profesor.informe_profesor: lo llenado en el formulario más grado, sección y período de la base. */
    public function datosInforme(User $docente, array $datos): array
    {
        $contexto = $this->obtenerContextoInforme($docente, $datos);

        return [
            'grado' => $contexto['grado'],
            'seccion' => $contexto['seccion'],
            'periodo' => $contexto['periodo'],
            'profesor' => $datos['profesor'],
            'fecha_formateada' => FechaReporte::hoy(),
            'asunto' => $datos['asunto'],
            'desarrollo' => $datos['desarrollo'],
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }

    private function obtenerContextoInforme(User $docente, array $datos): array
    {
        // Solo secciones donde el docente enseña o es guía (404 si no)
        $seccion = $this->conducta->seccionPropia($docente, $datos['seccion_id']);
        if ((int) $seccion->grado_id !== (int) $datos['grado_id']) {
            throw ValidationException::withMessages(['seccion_id' => 'La sección no pertenece al grado seleccionado.']);
        }

        $periodo = PeriodoEscolar::find($datos['periodo_escolar_id']);
        if (! $periodo || (int) $periodo->anio_lectivo_id !== (int) $seccion->anio_lectivo_id) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El período no pertenece al año lectivo de la sección.']);
        }

        return [
            'grado' => $seccion->grado?->nombre,
            'seccion' => $seccion->nombre,
            'periodo' => $periodo->nombre,
        ];
    }

    public function generarLibretaNotas(User $docente, array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('boleta', $this->datosLibreta($docente, $datos));
    }

    /** Datos de la vista profesor.boleta: notas del período de la base más lo llenado en el formulario. */
    public function datosLibreta(User $docente, array $datos): array
    {
        $seccion = $this->reportes->seccion($docente, $datos['seccion_id']);
        if ((int) $seccion->grado_id !== (int) $datos['grado_id']) {
            throw ValidationException::withMessages(['seccion_id' => 'La sección no pertenece al grado seleccionado.']);
        }

        $notas = $this->reportes->notas($docente, $seccion, $datos['periodo_escolar_id']);
        $fila = $notas['estudiantes']->firstWhere('matricula_id', (int) $datos['matricula_id']);
        if (! $fila) {
            throw ValidationException::withMessages(['matricula_id' => 'El estudiante no está matriculado en la sección seleccionada.']);
        }

        return [
            'fecha_formateada' => FechaReporte::hoy(),
            'grado' => $seccion->grado?->nombre,
            'seccion' => $seccion->nombre,
            'periodo' => $notas['periodo']->nombre,
            'profesor' => $seccion->profesor?->nombre,
            'estudiante' => $fila['estudiante']->nombre,
            'conducta' => $datos['conducta'] ?? '',
            'conducta_descripcion' => $datos['conducta_descripcion'] ?? '',
            'observaciones' => $datos['observaciones'] ?? '',
            'materias' => $notas['materias']->map(fn ($m) => [
                'nombre' => $m['nombre'],
                'promedio' => $fila['promedios'][$m['asignacion_id']],
            ])->all(),
            'promedio_general' => $fila['promedio'],
        ];
    }

    public function generarRendimiento(User $docente, array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('rendimiento', $this->datosRendimiento($docente, $datos));
    }

    /** Datos de la vista profesor.rendimiento: boletas del período de una materia del docente. */
    public function datosRendimiento(User $docente, array $datos): array
    {
        $asignacion = Asignacion::with(['seccion.grado:id,nombre', 'asignatura'])
            ->where('profesor_id', $docente->profesor?->id ?? 0)
            ->where('seccion_id', $datos['seccion_id'])
            ->find($datos['asignacion_id']);
        if (! $asignacion) {
            throw ValidationException::withMessages(['asignacion_id' => 'La materia no está asignada a usted en la sección seleccionada.']);
        }
        $seccion = $asignacion->seccion;
        if ((int) $seccion->grado_id !== (int) $datos['grado_id']) {
            throw ValidationException::withMessages(['seccion_id' => 'La sección no pertenece al grado seleccionado.']);
        }
        $periodo = PeriodoEscolar::find($datos['periodo_escolar_id']);
        if (! $periodo || (int) $periodo->anio_lectivo_id !== (int) $seccion->anio_lectivo_id) {
            throw ValidationException::withMessages(['periodo_escolar_id' => 'El período no pertenece al año lectivo de la sección.']);
        }

        $boletas = Boleta::where('asignacion_id', $asignacion->id)->where('periodo_escolar_id', $periodo->id)->pluck('promedio', 'matricula_id');
        $alumnos = $this->conducta->matriculas($seccion)->map(fn ($m) => [
            'nombre' => $m->estudiante?->nombre,
            'promedio' => isset($boletas[$m->id]) ? (float) $boletas[$m->id] : null,
        ]);
        $conNota = $alumnos->whereNotNull('promedio');

        return [
            'fecha_formateada' => FechaReporte::hoy(),
            'grado' => $seccion->grado?->nombre,
            'seccion' => $seccion->nombre,
            'profesor' => $datos['profesor'],
            'materia' => $asignacion->asignatura?->nombre,
            'periodo' => $periodo->nombre,
            'total_estudiantes' => $alumnos->count(),
            'promedio_general' => $conNota->isEmpty() ? null : round($conNota->avg('promedio'), 2),
            'nota_destacado' => self::NOTA_DESTACADO,
            'destacados' => $conNota->where('promedio', '>=', self::NOTA_DESTACADO)->sortByDesc('promedio')->values()->all(),
            'observaciones' => $datos['observaciones'] ?? '',
        ];
    }
}
