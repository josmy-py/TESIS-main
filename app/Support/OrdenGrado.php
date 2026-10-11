<?php

namespace App\Support;

/**
 * Orden académico de los grados (M2.1).
 *
 * El orden solo se deduce de nombres que no admiten otra lectura:
 *   - "<ordinal> grado" (1.º a 9.º de Educación Básica)        -> 1 a 9
 *   - "<ordinal> año [de] bachillerato [general]" (1.º a 3.º)   -> 10 a 12
 * El ordinal puede ir en letras (primer, primero, segundo, …, noveno) o en
 * cifras con o sin sufijo (1, 1.º, 1°, 1er, 1ro, 2do, …). Mayúsculas, tildes y
 * espacios extra no cuentan. Cualquier otro nombre (por ejemplo "1er Año",
 * "Parvularia 4" o "Kinder") queda sin orden y lo completa el director.
 */
class OrdenGrado
{
    private const ORDINALES = [
        1 => ['primer', 'primero', '1', '1er', '1ro', '1o'],
        2 => ['segundo', '2', '2do', '2o'],
        3 => ['tercer', 'tercero', '3', '3er', '3ro', '3o'],
        4 => ['cuarto', '4', '4to', '4o'],
        5 => ['quinto', '5', '5to', '5o'],
        6 => ['sexto', '6', '6to', '6o'],
        7 => ['septimo', 'setimo', '7', '7mo', '7o'],
        8 => ['octavo', '8', '8vo', '8o'],
        9 => ['noveno', '9', '9no', '9o'],
    ];

    public static function inferir(?string $nombre): ?int
    {
        $texto = self::normalizar((string) $nombre);

        if (preg_match('/^(\S+) grado$/', $texto, $m) && ($n = self::ordinal($m[1])) !== null) {
            return $n;
        }

        if (preg_match('/^(\S+) ano (?:de )?bachillerato(?: general)?$/', $texto, $m)
            && ($n = self::ordinal($m[1])) !== null && $n <= 3) {
            return 9 + $n;
        }

        return null;
    }

    private static function ordinal(string $palabra): ?int
    {
        foreach (self::ORDINALES as $numero => $formas) {
            if (in_array($palabra, $formas, true)) {
                return $numero;
            }
        }

        return null;
    }

    private static function normalizar(string $nombre): string
    {
        $texto = mb_strtolower(trim($nombre), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'º' => ' ', '°' => ' ', 'ª' => ' ', '.' => ' ',
        ]);

        // "1 er grado" -> "1er grado"
        $texto = preg_replace('/\b(\d) (er|ro|do|to|mo|vo|no|o)\b/', '$1$2', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }
}
