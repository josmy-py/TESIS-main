<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Estudiante;
use App\Models\Padre;
use App\Models\Pariente;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Vínculos padre/madre ↔ estudiante (tabla parientes, D3).
 * - Un estudiante tiene como máximo un "Padre" y una "Madre", sin vínculos repetidos.
 * - D-E4: el encargado solo vincula a personas de su familia (su propia cuenta,
 *   ya vinculadas a sus estudiantes o recién registradas sin estudiantes, que
 *   encuentra por su DUI). Si la persona pertenece a otra familia, el
 *   vínculo lo hace la dirección; la respuesta no revela datos de esa persona.
 * - D-E7: no se desvincula a la persona de la cuenta responsable del estudiante
 *   ni al último padre de un estudiante con matrícula vigente sin otro encargado.
 */
class ParientesService
{
    public const REQUIERE_DIRECCION = 'No se encontró a esa persona entre los registros de su familia. Si ya está registrada por otra familia, la dirección debe autorizar el vínculo.';

    /**
     * Con $propietarioId (cuenta ENCARGADO): el estudiante debe ser suyo y el
     * padre debe ser la propia cuenta o estar ya vinculado a uno de sus
     * estudiantes. Si no se puede comprobar la propiedad, se deniega. Lo ajeno
     * responde como no encontrado.
     */
    public function relacionarPadre($estudianteId, $padreId, $parentesco, ?int $propietarioId = null)
    {
        $this->estudiante($estudianteId, $propietarioId);

        $padre = Padre::findOrFail($padreId);
        if ($propietarioId && ! $this->padrePermitido($padre, $propietarioId)) {
            throw (new ModelNotFoundException)->setModel(Padre::class, [$padreId]);
        }

        $this->validarVinculo((int) $estudianteId, $padre->id, $parentesco);

        return Pariente::create([
            'estudiante_id' => $estudianteId,
            'padre_id' => $padreId,
            'parentesco' => $parentesco,
        ]);
    }

    /**
     * Vincula por DUI a una persona ya registrada. El director vincula a
     * cualquiera (queda en la bitácora); el encargado, solo a su familia.
     */
    public function vincularPorDui($estudianteId, string $dui, string $parentesco, User $cuenta, ?int $propietarioId): Padre
    {
        $estudiante = $this->estudiante($estudianteId, $propietarioId);
        $variantes = app(PadreService::class)->variantes($dui);
        $padre = Padre::whereIn('dui', $variantes)->first();

        if (! $propietarioId) {
            if (! $padre) {
                throw ValidationException::withMessages(['dui' => 'No hay ninguna persona registrada con ese DUI. Regístrela como padre o madre nuevo.']);
            }
        } elseif (! $padre || ! $this->padrePermitido($padre, $propietarioId)) {
            throw new \DomainException(self::REQUIERE_DIRECCION);
        }

        $this->validarVinculo($estudiante->id, $padre->id, $parentesco);

        Pariente::create(['estudiante_id' => $estudiante->id, 'padre_id' => $padre->id, 'parentesco' => $parentesco]);

        if (! $propietarioId) {
            $this->bitacora('vinculo_autorizado', $cuenta, [
                'estudiante_id' => $estudiante->id, 'estudiante' => $estudiante->nombre,
                'padre_id' => $padre->id, 'parentesco' => $parentesco,
            ]);
        }

        return $padre->fresh();
    }

    /** D-E7: protege las relaciones necesarias para matrículas o responsabilidades vigentes. */
    public function desvincular($estudianteId, $padreId, User $cuenta, ?int $propietarioId): void
    {
        $estudiante = $this->estudiante($estudianteId, $propietarioId);
        $padre = $estudiante->padres()->whereKey($padreId)->firstOrFail();

        if ($padre->user_id !== null && (int) $padre->user_id === (int) $estudiante->user_id) {
            throw new \DomainException("No se puede desvincular a {$padre->nombre}: es la persona de la cuenta responsable del estudiante.");
        }

        if ($estudiante->padres()->count() === 1 && $this->tieneMatriculaVigenteSinEncargado($estudiante)) {
            throw new \DomainException("No se puede desvincular a {$padre->nombre}: es el único padre o madre registrado y el estudiante tiene una matrícula vigente sin otro encargado.");
        }

        $estudiante->padres()->detach($padre->id);

        $this->bitacora('vinculo_quitado', $cuenta, [
            'estudiante_id' => $estudiante->id, 'estudiante' => $estudiante->nombre,
            'padre_id' => $padre->id, 'parentesco' => $padre->pivot->parentesco,
        ]);
    }

    /** Un "Padre" y una "Madre" como máximo por estudiante, sin repetir a la persona. */
    public function validarVinculo(int $estudianteId, int $padreId, ?string $parentesco): void
    {
        $vinculos = Pariente::where('estudiante_id', $estudianteId)->get(['padre_id', 'parentesco']);

        if ($vinculos->contains('padre_id', $padreId)) {
            throw ValidationException::withMessages(['dui' => 'Esta persona ya está vinculada con el estudiante.']);
        }
        if ($vinculos->contains('parentesco', $parentesco)) {
            $quien = $parentesco === 'Madre' ? 'una madre registrada' : 'un padre registrado';
            throw ValidationException::withMessages(['parentesco' => "El estudiante ya tiene {$quien}."]);
        }
    }

    private function estudiante($id, ?int $propietarioId): Estudiante
    {
        return Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
            ->findOrFail($id);
    }

    public function padrePermitido(Padre $padre, int $propietarioId): bool
    {
        if ($padre->user_id !== null) {
            return (int) $padre->user_id === $propietarioId;
        }

        // Sin cuenta propia: si ya comparte un estudiante con esta cuenta, o si aún no
        // tiene ningún estudiante (se registró primero y se busca por su DUI)
        return ! $padre->estudiantes()->where('estudiantes.user_id', '!=', $propietarioId)->exists();
    }

    /** Matrícula pendiente o aprobada de un año no cerrado cuyo responsable son los padres. */
    private function tieneMatriculaVigenteSinEncargado(Estudiante $estudiante): bool
    {
        return $estudiante->matriculas()
            ->whereIn('estado', ['pendiente', 'aprobado'])
            ->whereNull('encargado_id')
            ->where(fn ($q) => $q->whereNull('anio_lectivo_id')
                ->orWhereHas('anioLectivo', fn ($a) => $a->where('estado', '!=', AnioLectivo::CIERRE)))
            ->exists();
    }

    private function bitacora(string $accion, User $autor, array $datos): void
    {
        Log::channel('familias')->info($accion, $datos + ['por' => "{$autor->id} {$autor->email}"]);
    }
}
