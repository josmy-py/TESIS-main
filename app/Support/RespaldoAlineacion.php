<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Respaldo de los datos que la alineación con el diagrama quita de la base
 * (columnas y tabla fuera del diagrama). Se guarda como JSON en
 * storage/app/respaldos/ para poder consultarlos y para que revertir las
 * migraciones devuelva exactamente los valores anteriores.
 */
class RespaldoAlineacion
{
    public static function directorio(): string
    {
        // Las pruebas automáticas nunca tocan los respaldos reales
        return app()->runningUnitTests() ? storage_path('framework/testing/respaldos') : storage_path('app/respaldos');
    }

    public static function ruta(string $parte): string
    {
        return self::directorio()."/alineacion-diagrama-{$parte}.json";
    }

    public static function guardar(string $parte, array $datos): void
    {
        // Base vacía (instalación nueva o pruebas): no hay nada que respaldar
        if (collect($datos)->flatten()->filter(fn ($v) => $v !== null)->isEmpty()) {
            return;
        }

        File::ensureDirectoryExists(dirname(self::ruta($parte)));

        // Si ya existe un respaldo (la migración se aplicó antes), se conserva con su fecha
        if (File::exists(self::ruta($parte))) {
            File::move(self::ruta($parte), str_replace('.json', '-'.date('Ymd-His').'.json', self::ruta($parte)));
        }

        $json = json_encode(['creado' => now()->toDateTimeString()] + $datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        File::put(self::ruta($parte), $json);
    }

    /** Devuelve el respaldo como arreglos asociativos, o null si no existe. */
    public static function leer(string $parte): ?array
    {
        if (! File::exists(self::ruta($parte))) {
            return null;
        }

        return json_decode(File::get(self::ruta($parte)), true);
    }

    /** Marca el respaldo como ya restaurado para que no se aplique dos veces. */
    public static function archivar(string $parte): void
    {
        if (File::exists(self::ruta($parte))) {
            File::move(self::ruta($parte), str_replace('.json', '-restaurado-'.date('Ymd-His').'.json', self::ruta($parte)));
        }
    }

    /** Filas de una tabla con las columnas indicadas, como arreglos. */
    public static function filas($consulta, array $columnas): array
    {
        return $consulta->orderBy('id')->get($columnas)->map(fn ($fila) => (array) $fila)->all();
    }
}
