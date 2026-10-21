<?php

namespace App\Services\Profesor;

use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\ConductaService;
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
 */
class ReportesProfesorPdfService
{
    public const VISTAS = [
        'boleta' => 'profesor.boleta',
        'conducta' => 'profesor.conducta',
        'informe_profesor' => 'profesor.informe_profesor',
        'rendimiento' => 'profesor.rendimiento',
    ];

    public function __construct(private ConductaService $conducta)
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
}
