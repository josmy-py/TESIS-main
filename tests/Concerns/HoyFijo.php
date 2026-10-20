<?php

namespace Tests\Concerns;

use App\Models\AnioLectivo;
use Illuminate\Support\Carbon;

/**
 * Fija el día de hoy en las pruebas que dependen de las fechas del año lectivo
 * (periodo de matrícula, año comenzado), para que no cambien con el calendario
 * real. Por defecto el 01/12/2025: los años 2026 en adelante aún no comienzan.
 */
trait HoyFijo
{
    protected function setUpHoyFijo(): void
    {
        $this->fijarHoy($this->hoyDePrueba());
    }

    protected function hoyDePrueba(): string
    {
        return '2025-12-01';
    }

    protected function fijarHoy(string $fecha): void
    {
        // Mediodía en El Salvador, expresado en UTC como el resto de la aplicación
        Carbon::setTestNow(Carbon::parse($fecha.' 12:00:00', AnioLectivo::ZONA_HORARIA)->utc());
    }
}
