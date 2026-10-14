<?php

namespace Database\Seeders;

use App\Models\Discapacidad;
use App\Models\Enfermedad;
use Illuminate\Database\Seeder;

/**
 * Catálogos de salud de la matrícula: enfermedades (nombre y medicamento) y
 * discapacidades. Idempotente: busca por nombre y solo crea lo que falta; no
 * modifica ni borra registros existentes. El medicamento del catálogo queda
 * vacío: lo escribe el encargado en la matrícula. No se crea «Ninguna» (D-E9):
 * sin enfermedad, el campo queda vacío.
 */
class CatalogoSaludSeeder extends Seeder
{
    public const ENFERMEDADES = [
        'Asma',
        'Rinitis alérgica',
        'Dermatitis atópica',
        'Alergia alimentaria',
        'Alergia a medicamentos',
        'Epilepsia',
        'Diabetes tipo 1',
        'Anemia',
        'Hipotiroidismo',
        'Cardiopatía congénita',
        'Gastritis',
        'Migraña',
        'Trastorno por déficit de atención (TDAH)',
        'Enfermedad renal crónica',
    ];

    public const DISCAPACIDADES = [
        'Visual',
        'Auditiva',
        'Física o motora',
        'Intelectual',
        'Del habla o del lenguaje',
        'Trastorno del espectro autista',
        'Psicosocial',
        'Múltiple',
    ];

    public function run(): void
    {
        $creadas = 0;
        foreach (self::ENFERMEDADES as $nombre) {
            $creadas += Enfermedad::firstOrCreate(['nombre' => $nombre], ['medicamento' => ''])->wasRecentlyCreated ? 1 : 0;
        }

        $creadasD = 0;
        foreach (self::DISCAPACIDADES as $nombre) {
            $creadasD += Discapacidad::firstOrCreate(['nombre' => $nombre])->wasRecentlyCreated ? 1 : 0;
        }

        $this->command?->info("Catálogo de salud: {$creadas} enfermedad(es) y {$creadasD} discapacidad(es) nuevas.");
    }
}
