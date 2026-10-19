<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Catálogos sin nombres repetidos (pedido de Josmy, 19/10/2026): dos nombres
 * son el mismo si solo difieren en mayúsculas, tildes o espacios
 * ("Informática" = "informatica  "). Se compara en PHP para que la regla sea
 * igual en MySQL y en las pruebas (SQLite).
 */
class NombreUnico
{
    public static function clave(?string $nombre): string
    {
        return Str::of(Str::ascii((string) $nombre))->lower()->squish()->value();
    }

    /** ¿La consulta ya tiene una fila cuyo $columna equivale a $nombre? */
    public static function ocupado($consulta, string $columna, ?string $nombre): bool
    {
        $clave = self::clave($nombre);

        return $consulta->pluck($columna)->contains(fn ($valor) => self::clave($valor) === $clave);
    }

    /** Regla de validación de Laravel para el campo nombre de un catálogo. */
    public static function regla($consulta, string $columna, string $mensaje): \Closure
    {
        return function (string $atributo, $valor, \Closure $falla) use ($consulta, $columna, $mensaje) {
            if (is_string($valor) && self::ocupado(clone $consulta, $columna, $valor)) {
                $falla($mensaje);
            }
        };
    }
}
