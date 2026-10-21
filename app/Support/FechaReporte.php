<?php

namespace App\Support;

/**
 * Fecha que llevan los reportes PDF: el día en que se generan (Josmy,
 * 21/10/2026), en la hora de El Salvador aunque la aplicación use UTC.
 */
class FechaReporte
{
    public const ZONA = 'America/El_Salvador';

    public static function hoy(): string
    {
        return now(self::ZONA)->format('d/m/Y');
    }
}
