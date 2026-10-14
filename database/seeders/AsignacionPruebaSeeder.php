<?php

namespace Database\Seeders;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\Grado;
use App\Models\PlanEstudio;
use App\Models\Seccion;
use App\Models\User;
use App\Services\AsignacionService;
use Illuminate\Database\Seeder;

class AsignacionPruebaSeeder extends Seeder
{
    /** Pensum de ejemplo por grado (solo si el grado no tiene un plan vigente sin especialidad). */
    public const PENSUM = [
        'Primer grado' => ['Matemática', 'Lenguaje', 'Ciencia y Tecnología', 'Estudios Sociales'],
        'Segundo grado' => ['Matemática', 'Lenguaje', 'Ciencia y Tecnología', 'Estudios Sociales'],
    ];

    /** Materias que se asignan a docente@siga.test en su sección de cada grado. */
    public const ASIGNADAS = ['Matemática', 'Lenguaje'];

    /**
     * Carga académica de ejemplo (M4) para docente@siga.test, en las secciones de
     * SeccionPruebaSeeder. Usa el plan vigente del grado o crea uno de prueba con
     * sus materias. Respeta las reglas de M4 (no asigna en años cerrados, ni
     * materias que ya imparte otro docente, ni mezcla planes en una sección).
     * Idempotente. No se ejecuta en producción.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('AsignacionPruebaSeeder no se ejecuta en producción.');
            return;
        }

        $profesor = User::where('email', 'docente@siga.test')->first()?->profesor;
        if (! $profesor) {
            $this->command?->warn('AsignacionPruebaSeeder: falta el docente de prueba (ProfesorPruebaSeeder).');
            return;
        }

        $servicio = app(AsignacionService::class);

        foreach (self::PENSUM as $nombreGrado => $materias) {
            $grado = Grado::where('nombre', $nombreGrado)->first();
            $seccion = $grado ? Seccion::with('anioLectivo')
                ->where('grado_id', $grado->id)
                ->where('profesor_id', $profesor->id)
                ->whereHas('anioLectivo', fn ($q) => $q->where('estado', '<>', AnioLectivo::CIERRE))
                ->orderByDesc('anio_lectivo_id')
                ->first() : null;

            if (! $seccion) {
                continue;
            }

            $plan = $servicio->planDeSeccion($seccion->id) ?? $this->planVigente($grado, $seccion->anioLectivo);
            if (! $plan->estado) {
                continue;
            }

            foreach ($materias as $materia) {
                Asignatura::firstOrCreate(['plan_estudio_id' => $plan->id, 'nombre' => $materia]);
            }

            foreach (self::ASIGNADAS as $materia) {
                $asignatura = Asignatura::where('plan_estudio_id', $plan->id)->where('nombre', $materia)->first();
                if (! $asignatura || Asignacion::where('seccion_id', $seccion->id)->where('asignatura_id', $asignatura->id)->exists()) {
                    continue;
                }

                $servicio->crear([
                    'profesor_id' => $profesor->id,
                    'seccion_id' => $seccion->id,
                    'asignatura_id' => $asignatura->id,
                ]);
            }
        }
    }

    private function planVigente(Grado $grado, AnioLectivo $anio): PlanEstudio
    {
        $nombre = mb_substr("Plan prueba {$grado->nombre}", 0, 30);

        return PlanEstudio::where('nombre', $nombre)->whereNull('especialidad_id')->where('estado', true)->first()
            ?? PlanEstudio::create([
                'nombre' => $nombre,
                'especialidad_id' => null,
                'estado' => true,
                'fecha_inicio' => $anio->fecha_inicio ?? "{$anio->nombre}-01-15",
                'fecha_fin' => $anio->fecha_fin ?? "{$anio->nombre}-11-30",
                'fecha_aprobacion' => now()->toDateString(),
                'descripcion' => 'Plan de ejemplo para pruebas (AsignacionPruebaSeeder)',
            ]);
    }
}
