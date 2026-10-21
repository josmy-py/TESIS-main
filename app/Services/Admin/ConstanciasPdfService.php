<?php

namespace App\Services\Admin;

use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\LibretaService;
use App\Services\PromocionService;
use App\Support\CatalogoGrados;
use App\Support\FechaReporte;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use InvalidArgumentException;

/**
 * Constancias del portal director (dompdf). Cada constancia tiene su vista en
 * resources/views/constancias con el encabezado institucional.
 *
 * Constancia de Egresado (formulario y diseño de Josmy del 21/10/2026): los datos
 * del estudiante (nombre, opción, grado, sección y año lectivo) se buscan por el
 * NIE. Solo se emite si el estudiante está registrado y terminó su bachillerato:
 * tiene una matrícula aprobada en el último año de su opción con nota anual
 * Aprobada (promedio >= PromocionService::NOTA_MINIMA, la de la libreta).
 * - Bachillerato General (o matrícula sin especialidad): Segundo año de bachillerato.
 * - Técnico Vocacional (cualquier otra especialidad): tercer año, que hoy no está
 *   en el catálogo de grados; mientras no exista no se puede emitir.
 * No se guarda nada en la base de datos. La fecha es la del día en que se genera.
 */
class ConstanciasPdfService
{
    public const VISTAS = [
        'egresado' => 'constancias.egresado',
    ];

    /** Orden del tercer año de bachillerato (solo modalidades técnicas, aún fuera del catálogo). */
    public const ORDEN_TERCER_ANIO = 12;

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function generar(string $constancia, array $datos = []): \Barryvdh\DomPDF\PDF
    {
        $vista = self::VISTAS[$constancia] ?? throw new InvalidArgumentException("Constancia desconocida: {$constancia}");

        return Pdf::loadView($vista, $datos)->setPaper('letter', 'portrait');
    }

    /**
     * Datos del estudiante egresado que llenan el formulario. Sin estudiante con
     * ese NIE: ModelNotFoundException (404). Si no terminó: DomainException (409)
     * con el motivo.
     */
    public function egresado(string $nie): array
    {
        $estudiante = Estudiante::where('nie', trim($nie))->firstOrFail();

        $aprobadas = Matricula::with(['seccion.grado', 'anioLectivo', 'especialidad'])
            ->where('estudiante_id', $estudiante->id)
            ->where('estado', 'aprobado')
            ->get()
            ->sortByDesc(fn (Matricula $m) => [(string) $m->anioLectivo?->fecha_inicio, $m->id])
            ->values();

        $bachillerato = $aprobadas->filter(fn (Matricula $m) => ($m->seccion?->grado?->orden ?? -99) >= CatalogoGrados::GRADOS['Primer año de bachillerato']);
        if ($bachillerato->isEmpty()) {
            throw new DomainException("{$estudiante->nombre} no tiene matrículas aprobadas de bachillerato en la institución.");
        }

        // Matrícula del último año de su opción con nota anual aprobada
        $final = $bachillerato->first(fn (Matricula $m) => $m->seccion->grado->orden === $this->ordenFinal($m) && $this->aprobo($m));
        if ($final) {
            return $this->datosEstudiante($estudiante, $final);
        }

        $ultima = $bachillerato->first();
        $grado = $ultima->seccion->grado->nombre;
        $anio = $ultima->anioLectivo?->anio ?? '(año sin registrar)';

        if ($ultima->seccion->grado->orden < $this->ordenFinal($ultima)) {
            if ($this->ordenFinal($ultima) === self::ORDEN_TERCER_ANIO && ! in_array(self::ORDEN_TERCER_ANIO, CatalogoGrados::GRADOS, true)) {
                throw new DomainException("La opción {$this->opcion($ultima)} termina en tercer año de bachillerato, que aún no está en el catálogo de grados de SIGA: no se puede emitir la constancia de egresado.");
            }

            throw new DomainException("{$estudiante->nombre} aún no ha terminado el bachillerato: su última matrícula aprobada es de {$grado} ({$anio}).");
        }

        if (! $ultima->notaFinal()->exists()) {
            throw new DomainException("La matrícula de {$grado} ({$anio}) de {$estudiante->nombre} aún no tiene nota anual registrada.");
        }

        throw new DomainException("{$estudiante->nombre} no aprobó {$grado} en {$anio}.");
    }

    public function generarConstanciaEgresado(array $datos): \Barryvdh\DomPDF\PDF
    {
        return $this->generar('egresado', $this->datosConstanciaEgresado($datos));
    }

    /** Datos de la vista constancias.egresado: estudiante buscado por NIE más el director del formulario. */
    public function datosConstanciaEgresado(array $datos): array
    {
        return [
            'estudiante' => $this->egresado($datos['nie']),
            'director' => $datos['director'],
            'fecha' => $this->fechaLarga(),
        ];
    }

    /** Fecha del día en que se genera, en letras de mes: "21 de octubre de 2026". */
    public function fechaLarga(): string
    {
        $hoy = now(FechaReporte::ZONA);

        return $hoy->day.' de '.self::MESES[$hoy->month - 1].' de '.$hoy->year;
    }

    private function datosEstudiante(Estudiante $estudiante, Matricula $matricula): array
    {
        return [
            'nombre' => $estudiante->nombre,
            'nie' => $estudiante->nie,
            'nivel' => CatalogoGrados::nivel($matricula->seccion->grado->orden),
            'opcion' => $this->opcion($matricula),
            'grado' => $matricula->seccion->grado->nombre,
            'seccion' => $matricula->seccion->nombre,
            'anio_lectivo' => (string) ($matricula->anioLectivo?->anio ?? ''),
        ];
    }

    private function aprobo(Matricula $matricula): bool
    {
        $nota = $matricula->notaFinal()->latest('id')->first();

        return $nota && $nota->estado === LibretaService::APROBADA && (float) $nota->promedio_anual >= PromocionService::NOTA_MINIMA;
    }

    private function esGeneral(Matricula $matricula): bool
    {
        $nombre = mb_strtolower((string) $matricula->especialidad?->nombre, 'UTF-8');

        return $matricula->especialidad === null || str_contains($nombre, 'general');
    }

    /** Orden del último grado de la opción: Segundo año (General) o tercer año (Técnico Vocacional). */
    private function ordenFinal(Matricula $matricula): int
    {
        return $this->esGeneral($matricula) ? CatalogoGrados::GRADOS['Segundo año de bachillerato'] : self::ORDEN_TERCER_ANIO;
    }

    private function opcion(Matricula $matricula): string
    {
        return $this->esGeneral($matricula) ? 'Bachillerato General' : $matricula->especialidad->nombre;
    }
}
