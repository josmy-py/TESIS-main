<?php

namespace App\Services;

use App\Models\Estudiante;
use App\Models\Padre;
use App\Models\Pariente;
use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;

class PadreService
{
    public function obtener($id)
    {
        return Padre::with('user')->findOrFail($id);
    }

    /**
     * Con estudiante_id y parentesco, el padre se registra ya vinculado a ese
     * estudiante (tabla parientes) en la misma transacción. Así un padre
     * registrado por un encargado siempre pertenece a uno de sus estudiantes.
     */
    public function crear(array $data): Padre
    {
        if (! empty($data['user_id'])) {
            $existe = Padre::where('user_id', $data['user_id'])->exists();

            if ($existe) {
                throw new \DomainException('El usuario autenticado ya está registrado como padre.');
            }
        }

        return DB::transaction(function () use ($data) {
            $padre = Padre::create([
                'nombre' => $data['nombre'],
                'dui' => $data['dui'],
                'telefono' => $data['telefono'],
                'email' => $data['email'],
                'user_id' => $data['user_id'] ?? null,
            ]);

            if (! empty($data['estudiante_id'])) {
                Pariente::create([
                    'estudiante_id' => $data['estudiante_id'],
                    'padre_id' => $padre->id,
                    'parentesco' => $data['parentesco'],
                ]);
            }

            return $padre;
        });
    }

    public function actualizar($id, array $data)
    {
        $padre = Padre::findOrFail($id);

        $padre->update([
            'nombre' => $data['nombre'],
            'dui' => $data['dui'],
            'telefono' => $data['telefono'],
            'email' => $data['email'],
            'user_id' => $data['user_id'] ?? null,
        ]);

        return $padre->load('user');
    }
    /**
     * Con $propietarioId (cuenta ENCARGADO) solo se encuentra el padre que es
     * la propia cuenta o que ya está vinculado a uno de sus estudiantes.
     */
    public function buscarPorDui($dui, ?int $propietarioId = null)
    {
        $normalizado = DuiFormato::normalizar($dui);

        return Padre::whereIn('dui', array_unique([$normalizado, str_replace('-', '', $normalizado)]))
            ->when($propietarioId, fn ($q) => $q->where(function ($q) use ($propietarioId) {
                $q->where('user_id', $propietarioId)
                    ->orWhereHas('estudiantes', fn ($e) => $e->where('estudiantes.user_id', $propietarioId));
            }))
            ->first();
    }
}
