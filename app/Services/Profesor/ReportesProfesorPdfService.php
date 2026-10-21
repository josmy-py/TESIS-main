<?php

namespace App\Services\Profesor;

use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\ConductaService;
use App\Services\ReporteService;
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
 */
class ReportesProfesorPdfService
{
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
            'fecha_formateada' => date('d/m/Y', strtotime($datos['fecha'])),
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
            'fecha_formateada' => date('d/m/Y', strtotime($datos['fecha'])),
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
}
