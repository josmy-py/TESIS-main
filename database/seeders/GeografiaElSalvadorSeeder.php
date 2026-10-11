<?php

namespace Database\Seeders;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Departamentos, municipios y distritos de El Salvador (M2.1.5).
 *
 * Idempotente: busca cada registro por nombre dentro de su padre y solo crea
 * lo que falta. No borra ni modifica registros existentes, de modo que los
 * distritos ya referenciados por estudiantes conservan su id. Origen de los
 * datos: database/data/geografia_el_salvador.php.
 */
class GeografiaElSalvadorSeeder extends Seeder
{
    public const ARCHIVO = 'data/geografia_el_salvador.php';

    public function run(): void
    {
        $datos = require database_path(self::ARCHIVO);
        $creados = ['departamentos' => 0, 'municipios' => 0, 'distritos' => 0];

        DB::transaction(function () use ($datos, &$creados) {
            foreach ($datos as $nombreDepartamento => $municipios) {
                $departamento = Departamento::firstOrCreate(['nombre' => $nombreDepartamento]);
                $creados['departamentos'] += (int) $departamento->wasRecentlyCreated;

                foreach ($municipios as $nombreMunicipio => $distritos) {
                    $municipio = Municipio::firstOrCreate([
                        'nombre' => $nombreMunicipio,
                        'departamento_id' => $departamento->id,
                    ]);
                    $creados['municipios'] += (int) $municipio->wasRecentlyCreated;

                    foreach ($distritos as $nombreDistrito) {
                        $distrito = Distrito::firstOrCreate([
                            'nombre' => $nombreDistrito,
                            'municipio_id' => $municipio->id,
                        ]);
                        $creados['distritos'] += (int) $distrito->wasRecentlyCreated;
                    }
                }
            }
        });

        $this->command?->info(sprintf(
            'Geografía de El Salvador: %d departamentos, %d municipios y %d distritos nuevos (los existentes no se modificaron).',
            $creados['departamentos'], $creados['municipios'], $creados['distritos']
        ));
    }
}
