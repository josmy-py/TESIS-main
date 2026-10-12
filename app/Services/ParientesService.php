<?php

namespace App\Services;

use App\Models\Pariente;
use App\Models\Estudiante;
use App\Models\Padre;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ParientesService
{
    /**
     * Con $propietarioId (cuenta ENCARGADO): el estudiante debe ser suyo y el
     * padre debe ser la propia cuenta o estar ya vinculado a uno de sus
     * estudiantes. Si no se puede
     * comprobar la propiedad, se deniega. Lo ajeno responde como no encontrado.
     */
    public function relacionarPadre($estudianteId, $padreId, $parentesco, ?int $propietarioId = null)
    {
        Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
            ->findOrFail($estudianteId);

        $padre = Padre::findOrFail($padreId);

        if ($propietarioId && ! $this->padrePermitido($padre, $propietarioId)) {
            throw (new ModelNotFoundException)->setModel(Padre::class, [$padreId]);
        }

        return Pariente::create([
            'estudiante_id' => $estudianteId,
            'padre_id'       => $padreId,
            'parentesco'     => $parentesco,
        ]);
    }

    private function padrePermitido(Padre $padre, int $propietarioId): bool
    {
        if ($padre->user_id !== null) {
            return (int) $padre->user_id === $propietarioId;
        }

        // Sin cuenta propia: solo si ya comparte un estudiante con esta cuenta
        return $padre->estudiantes()->where('estudiantes.user_id', $propietarioId)->exists();
    }
}
