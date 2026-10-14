<?php

namespace App\Services;

use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;

/**
 * M5: revisión de solo lectura de los datos familiares existentes antes de
 * aplicar las reglas nuevas (una persona por DUI, un Padre y una Madre por
 * estudiante, NIE numérico). No modifica nada.
 */
class DiagnosticoFamiliasService
{
    public function ejecutar(): array
    {
        return [
            'padres_dui_repetido' => $this->duiRepetido('padres'),
            'encargados_dui_repetido' => $this->duiRepetido('encargados'),
            'vinculos_repetidos' => DB::table('parientes')
                ->select('estudiante_id', 'padre_id', DB::raw('COUNT(*) as total'))
                ->groupBy('estudiante_id', 'padre_id')->havingRaw('COUNT(*) > 1')
                ->get()->map(fn ($f) => (array) $f)->all(),
            'parentesco_repetido' => DB::table('parientes')
                ->select('estudiante_id', 'parentesco', DB::raw('COUNT(*) as total'))
                ->groupBy('estudiante_id', 'parentesco')->havingRaw('COUNT(*) > 1')
                ->get()->map(fn ($f) => (array) $f)->all(),
            'nie_no_valido' => DB::table('estudiantes')->whereNotNull('nie')->get(['id', 'nombre', 'nie'])
                ->filter(fn ($e) => ! preg_match('/^[0-9]{1,10}$/', $e->nie))
                ->map(fn ($f) => (array) $f)->values()->all(),
        ];
    }

    /** Informativo: personas sin cuenta ni estudiantes (solo la dirección puede vincularlas). */
    public function padresSinVinculo(): int
    {
        return DB::table('padres')->whereNull('user_id')
            ->whereNotIn('id', DB::table('parientes')->select('padre_id'))->count();
    }

    private function duiRepetido(string $tabla): array
    {
        return DB::table($tabla)->get(['id', 'nombre', 'dui'])
            ->groupBy(fn ($f) => str_replace('-', '', (string) $f->dui))
            ->filter(fn ($grupo) => $grupo->count() > 1)
            ->map(fn ($grupo, $dui) => ['tabla' => $tabla, 'dui' => DuiFormato::normalizar((string) $dui), 'ids' => $grupo->pluck('id')->implode(', ')])
            ->values()->all();
    }
}
