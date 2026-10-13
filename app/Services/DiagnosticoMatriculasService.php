<?php

namespace App\Services;

use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * M6.1: revisión de solo lectura de las matrículas existentes contra las
 * reglas nuevas (una vigente por año, cupo, sección y plan, responsable,
 * archivos privados). No modifica nada.
 */
class DiagnosticoMatriculasService
{
    public function __construct(private MatriculaService $matriculas) {}

    public function ejecutar(): array
    {
        $vigentes = Matricula::with(['seccion', 'estudiante.padres', 'encargado'])
            ->whereIn('estado', MatriculaService::VIGENTES)->orderBy('id')->get();
        $asignacion = app(AsignacionService::class);
        $planes = [];

        return [
            'duplicadas' => DB::table('matriculas')
                ->select('estudiante_id', 'anio_lectivo_id', DB::raw('COUNT(*) as total'))
                ->whereIn('estado', MatriculaService::VIGENTES)
                ->groupBy('estudiante_id', 'anio_lectivo_id')->havingRaw('COUNT(*) > 1')
                ->get()->map(fn ($f) => (array) $f)->all(),
            'secciones_sobre_cupo' => DB::table('secciones')
                ->join('matriculas', 'matriculas.seccion_id', '=', 'secciones.id')
                ->whereIn('matriculas.estado', MatriculaService::VIGENTES)
                ->groupBy('secciones.id', 'secciones.nombre', 'secciones.capacidad')
                ->havingRaw('COUNT(*) > secciones.capacidad')
                ->get(['secciones.id', 'secciones.nombre', 'secciones.capacidad', DB::raw('COUNT(*) as ocupados')])
                ->map(fn ($f) => (array) $f)->all(),
            'aprobadas_sin_seccion' => $this->filas($vigentes->where('estado', 'aprobado')->whereNull('seccion_id')),
            'seccion_de_otro_anio' => $this->filas($vigentes->filter(
                fn ($m) => $m->seccion && (int) $m->seccion->anio_lectivo_id !== (int) $m->anio_lectivo_id
            )),
            'especialidad_distinta_del_plan' => $vigentes->filter(function ($m) use ($asignacion, &$planes) {
                if (! $m->seccion_id) {
                    return false;
                }
                $plan = $planes[$m->seccion_id] ??= ($asignacion->planDeSeccion($m->seccion_id) ?: false);

                return $plan && PromocionService::id($plan->especialidad_id) !== PromocionService::id($m->especialidad_id);
            })->map(fn ($m) => $this->fila($m) + ['especialidad_id' => $m->especialidad_id ?? '(ninguna)', 'plan' => $planes[$m->seccion_id]->nombre])->values()->all(),
            'sin_responsable' => $this->filas($vigentes->filter(fn ($m) => $this->matriculas->responsableDe($m) === null)),
            'archivos_publicos' => Matricula::where('foto', 'like', '/storage/%')->orWhere('documento', 'like', '/storage/%')
                ->orderBy('id')->get()->map(fn ($m) => $this->fila($m) + ['foto' => $m->foto, 'documento' => $m->documento ?? '(vacío)'])->all(),
            'archivos_faltantes' => Matricula::orderBy('id')->get()->flatMap(fn ($m) => collect(['foto', 'documento'])
                ->filter(fn ($c) => $m->{$c} && ! str_starts_with($m->{$c}, '/storage/') && ! Storage::disk(MatriculaService::DISCO)->exists($m->{$c}))
                ->map(fn ($c) => $this->fila($m) + ['archivo' => $c, 'ruta' => $m->{$c}]))->values()->all(),
        ];
    }

    /** Informativo (D-M4): pendientes de datos anteriores sin sección; la dirección la asigna al aprobar. */
    public function pendientesSinSeccion(): int
    {
        return Matricula::where('estado', 'pendiente')->whereNull('seccion_id')->count();
    }

    private function filas($matriculas): array
    {
        return $matriculas->map(fn ($m) => $this->fila($m))->values()->all();
    }

    private function fila(Matricula $m): array
    {
        return ['id' => $m->id, 'estudiante_id' => $m->estudiante_id, 'anio_lectivo_id' => $m->anio_lectivo_id, 'seccion_id' => $m->seccion_id ?? '(vacío)', 'estado' => $m->estado];
    }
}
