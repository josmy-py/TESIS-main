<?php

namespace App\Support;

use App\Rules\Dui;

/**
 * Formato único del DUI en SIGA: 00000000-0.
 * El dígito verificador lo valida App\Rules\Dui (no se duplica el algoritmo).
 */
class DuiFormato
{
    /**
     * Devuelve el DUI como 00000000-0 si tiene 9 dígitos (con o sin guion
     * y espacios). Si no, lo devuelve sin cambios para que la validación lo rechace.
     */
    public static function normalizar(?string $dui): ?string
    {
        if ($dui === null) {
            return null;
        }

        $limpio = preg_replace('/[\s-]/', '', $dui);

        if (! preg_match('/^[0-9]{9}$/', $limpio)) {
            return trim($dui);
        }

        return substr($limpio, 0, 8).'-'.substr($limpio, 8, 1);
    }

    public static function esValido(?string $dui): bool
    {
        if ($dui === null || $dui === '') {
            return false;
        }

        $valido = true;
        (new Dui)->validate('dui', $dui, function () use (&$valido) {
            $valido = false;
        });

        return $valido;
    }
}
