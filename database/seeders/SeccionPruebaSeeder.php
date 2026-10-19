<?php

namespace Database\Seeders;

use App\Models\AnioLectivo;
use App\Models\Grado;
use App\Models\Seccion;
use App\Models\User;
use App\Services\CalendarioService;
use Illuminate\Database\Seeder;

class SeccionPruebaSeeder extends Seeder
{
    public const ANIO_PRUEBA = 2027;

    /**
     * Secciones de ejemplo (M2.4) a cargo de docente@siga.test (ProfesorPruebaSeeder).
     * Usa el año lectivo activo; si no hay, el último que no esté cerrado; si
     * tampoco hay, crea el año de prueba 2027 (inactivo, con sus periodos).
     * Idempotente: no duplica grados, años ni secciones. No se ejecuta en producción.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('SeccionPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $profesor = User::where('email', 'docente@siga.test')->first()?->profesor;
        if (! $profesor) {
            $this->command?->warn('SeccionPruebaSeeder: falta el docente de prueba (ProfesorPruebaSeeder).');
            return;
        }

        $anio = $this->anioLectivo();
        if (! $anio) {
            $this->command?->warn('SeccionPruebaSeeder: no hay un año lectivo abierto y el año de prueba '.self::ANIO_PRUEBA.' está cerrado.');
            return;
        }

        // Un mismo docente, una sección por turno (D-S2)
        foreach ([
            ['grado' => 'Primer grado', 'nombre' => 'A', 'turno' => 'matutino', 'capacidad' => 35],
            ['grado' => 'Segundo grado', 'nombre' => 'A', 'turno' => 'vespertino', 'capacidad' => 35],
        ] as $datos) {
            $grado = Grado::firstOrCreate(['nombre' => $datos['grado']]);

            $existe = Seccion::where('anio_lectivo_id', $anio->id)->where('grado_id', $grado->id)->where('nombre', $datos['nombre'])->exists();
            $turnoOcupado = Seccion::where('anio_lectivo_id', $anio->id)->where('profesor_id', $profesor->id)->where('turno', $datos['turno'])->exists();
            if ($existe || $turnoOcupado) {
                continue;
            }

            Seccion::create([
                'nombre' => $datos['nombre'],
                'capacidad' => $datos['capacidad'],
                'turno' => $datos['turno'],
                'anio_lectivo_id' => $anio->id,
                'grado_id' => $grado->id,
                'profesor_id' => $profesor->id,
            ]);
        }
    }

    private function anioLectivo(): ?AnioLectivo
    {
        $anio = AnioLectivo::activo()
            ?? AnioLectivo::where('estado', '<>', AnioLectivo::CIERRE)->orderByDesc('anio')->first();

        if ($anio || AnioLectivo::where('anio', self::ANIO_PRUEBA)->exists()) {
            return $anio;
        }

        $calendario = app(CalendarioService::class);
        $anio = $calendario->crear([
            'anio' => self::ANIO_PRUEBA,
            'fecha_inicio' => self::ANIO_PRUEBA.'-01-15',
            'fecha_fin' => self::ANIO_PRUEBA.'-11-30',
        ]);
        foreach ([1, 2, 3] as $nivel) {
            $calendario->agregarPeriodo($anio->id, ['nombre_periodo' => CalendarioService::nombrePredeterminado($nivel), 'nivel' => $nivel]);
        }

        return $anio->fresh('periodos');
    }
}
