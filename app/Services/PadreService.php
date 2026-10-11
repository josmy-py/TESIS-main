<?php

namespace App\Services;

use App\Models\Padre;
use App\Support\DuiFormato;

class PadreService
{
    public function obtener($id)
    {
        return Padre::with('user')->findOrFail($id);
    }

    public function crear(array $data): Padre
    {
        if (! empty($data['user_id'])) {
            $existe = Padre::where('user_id', $data['user_id'])->exists();

            if ($existe) {
                throw new \DomainException('El usuario autenticado ya está registrado como padre.');
            }
        }

        return Padre::create([
            'nombre' => $data['nombre'],
            'dui' => $data['dui'],
            'telefono' => $data['telefono'],
            'email' => $data['email'],
            'user_id' => $data['user_id'] ?? null,
            'registrado_por' => $data['registrado_por'] ?? null,
        ]);
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
     * la propia cuenta, que esa cuenta registró o que ya está vinculado a uno
     * de sus estudiantes.
     */
    public function buscarPorDui($dui, ?int $propietarioId = null)
    {
        $normalizado = DuiFormato::normalizar($dui);

        return Padre::whereIn('dui', array_unique([$normalizado, str_replace('-', '', $normalizado)]))
            ->when($propietarioId, fn ($q) => $q->where(function ($q) use ($propietarioId) {
                $q->where('user_id', $propietarioId)
                    ->orWhere(fn ($r) => $r->whereNull('user_id')->where('registrado_por', $propietarioId))
                    ->orWhereHas('estudiantes', fn ($e) => $e->where('estudiantes.user_id', $propietarioId));
            }))
            ->first();
    }
}
