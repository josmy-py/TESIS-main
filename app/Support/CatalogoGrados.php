<?php

namespace App\Support;

/**
 * Catálogo cerrado de grados (decisión de Josmy del 12/10/2026).
 *
 * El diagrama solo guarda el nombre del grado. Para que la promoción al
 * siguiente grado (tesis, pág. 63) no dependa de nombres libres, el nombre
 * solo puede ser uno de los oficiales y cada uno tiene su orden fijo. La
 * institución atiende Parvularia, Educación Básica de primero a noveno grado y
 * Bachillerato General (tesis, antecedentes), que dura dos años; el tercer año
 * solo existe en el Bachillerato Técnico Vocacional, que SIGA no ofrece.
 */
class CatalogoGrados
{
    public const GRADOS = [
        'Parvularia 4 años' => -2,
        'Parvularia 5 años' => -1,
        'Parvularia 6 años' => 0,
        'Primer grado' => 1,
        'Segundo grado' => 2,
        'Tercer grado' => 3,
        'Cuarto grado' => 4,
        'Quinto grado' => 5,
        'Sexto grado' => 6,
        'Séptimo grado' => 7,
        'Octavo grado' => 8,
        'Noveno grado' => 9,
        'Primer año de bachillerato' => 10,
        'Segundo año de bachillerato' => 11,
    ];

    /** Nombre oficial que corresponde al texto (sin distinguir mayúsculas, tildes ni espacios), o null. */
    public static function oficial(?string $nombre): ?string
    {
        $clave = self::clave((string) $nombre);
        foreach (array_keys(self::GRADOS) as $oficial) {
            if (self::clave($oficial) === $clave) {
                return $oficial;
            }
        }

        return null;
    }

    /** Nivel educativo del grado según su orden: Parvularia, Educación Básica o Bachillerato. */
    public static function nivel(?int $orden): ?string
    {
        return match (true) {
            $orden === null => null,
            $orden <= 0 => 'Parvularia',
            $orden <= 9 => 'Educación Básica',
            default => 'Bachillerato',
        };
    }

    public static function orden(?string $nombre): ?int
    {
        $oficial = self::oficial($nombre);

        return $oficial === null ? null : self::GRADOS[$oficial];
    }

    /**
     * Para nombres anteriores al catálogo: el nombre oficial equivalente solo
     * cuando no admite otra lectura ("1er grado", "2.º año bachillerato",
     * "Parvularia 4"). Si no hay uno inequívoco, null.
     */
    public static function equivalente(?string $nombre): ?string
    {
        if ($oficial = self::oficial($nombre)) {
            return $oficial;
        }

        $orden = OrdenGrado::inferir($nombre);
        if ($orden !== null) {
            return array_search($orden, self::GRADOS, true) ?: null;
        }

        if (preg_match('/^parvularia ([456])(?: anos)?$/', self::clave((string) $nombre), $m)) {
            return "Parvularia {$m[1]} años";
        }

        return null;
    }

    private static function clave(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $texto);
    }
}
