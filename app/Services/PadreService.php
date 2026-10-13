<?php

namespace App\Services;

use App\Models\Padre;
use App\Models\Pariente;
use App\Models\User;
use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Padres y madres (D3): una persona por DUI, vinculada a uno o varios
 * estudiantes mediante `parientes`. Con $propietarioId (cuenta ENCARGADO)
 * solo se ve al padre que es la propia cuenta o que ya está vinculado a
 * uno de sus estudiantes, y de sus estudiantes solo se muestran los propios.
 */
class PadreService
{
    public function __construct(private ParientesService $parientes)
    {
    }

    public function listar(?int $propietarioId = null, ?string $texto = null)
    {
        return $this->visibles($propietarioId)
            ->when(trim((string) $texto) !== '', function ($q) use ($texto) {
                $texto = trim($texto);
                $q->where(fn ($w) => $w->where('nombre', 'like', "%{$texto}%")->orWhere('dui', 'like', "{$texto}%"));
            })
            ->with(['estudiantes' => fn ($q) => $q->select('estudiantes.id', 'estudiantes.nombre', 'estudiantes.nie')
                ->when($propietarioId, fn ($e) => $e->where('estudiantes.user_id', $propietarioId))])
            ->orderBy('nombre')
            ->get()
            ->each(fn (Padre $p) => $p->setAttribute('editable', $this->puedeEditar($p, $propietarioId)));
    }

    public function obtener($id, ?int $propietarioId = null): Padre
    {
        $padre = $this->visibles($propietarioId)
            ->with(['estudiantes' => fn ($q) => $q->select('estudiantes.id', 'estudiantes.nombre', 'estudiantes.nie')
                ->when($propietarioId, fn ($e) => $e->where('estudiantes.user_id', $propietarioId))])
            ->findOrFail($id);

        return $padre->setAttribute('editable', $this->puedeEditar($padre, $propietarioId));
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
                $this->parientes->validarVinculo((int) $data['estudiante_id'], $padre->id, $data['parentesco']);
                Pariente::create([
                    'estudiante_id' => $data['estudiante_id'],
                    'padre_id' => $padre->id,
                    'parentesco' => $data['parentesco'],
                ]);
            }

            return $padre;
        });
    }

    /**
     * Verificación segura (D-E4): la cuenta cuyo DUI coincide con el de un padre
     * que aún no tiene cuenta es esa misma persona; se le asigna el registro y,
     * si se indica, se vincula con su estudiante.
     */
    public function reclamarPerfil(User $cuenta, array $data): ?Padre
    {
        $padre = Padre::whereIn('dui', $this->variantes($data['dui']))->first();

        if (! $padre || $padre->user_id !== null || DuiFormato::normalizar($data['dui']) !== $cuenta->dui) {
            return null;
        }
        if (Padre::where('user_id', $cuenta->id)->exists()) {
            throw new \DomainException('El usuario autenticado ya está registrado como padre.');
        }

        return DB::transaction(function () use ($padre, $cuenta, $data) {
            $padre->update([
                'nombre' => $data['nombre'],
                'telefono' => $data['telefono'] ?? $padre->telefono,
                'email' => $data['email'] ?? $padre->email,
                'user_id' => $cuenta->id,
            ]);

            if (! empty($data['estudiante_id']) && ! $padre->estudiantes()->whereKey($data['estudiante_id'])->exists()) {
                $this->parientes->validarVinculo((int) $data['estudiante_id'], $padre->id, $data['parentesco']);
                Pariente::create(['estudiante_id' => $data['estudiante_id'], 'padre_id' => $padre->id, 'parentesco' => $data['parentesco']]);
            }

            return $padre->fresh();
        });
    }

    /** D-E5: el encargado edita a su propio perfil o al padre vinculado solo con sus estudiantes. */
    public function actualizar($id, array $data, ?int $propietarioId = null): Padre
    {
        $padre = $this->visibles($propietarioId)->findOrFail($id);

        if (! $this->puedeEditar($padre, $propietarioId)) {
            throw new \DomainException('Esta persona tiene su propia cuenta o también está vinculada con otra familia: solo la dirección puede modificar sus datos.');
        }

        $dui = DuiFormato::normalizar($data['dui']);
        if ($padre->user_id !== null && $dui !== $padre->user?->dui) {
            throw ValidationException::withMessages(['dui' => 'El DUI debe coincidir con el de la cuenta de esta persona.']);
        }
        if (Padre::whereIn('dui', $this->variantes($dui))->whereKeyNot($padre->id)->exists()) {
            throw ValidationException::withMessages(['dui' => 'Este DUI ya pertenece a otra persona registrada.']);
        }

        $padre->update([
            'nombre' => $data['nombre'],
            'dui' => $dui,
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        return $this->obtener($padre->id, $propietarioId);
    }

    /**
     * Con $propietarioId (cuenta ENCARGADO) solo se encuentra el padre que es
     * la propia cuenta o que ya está vinculado a uno de sus estudiantes.
     */
    public function buscarPorDui($dui, ?int $propietarioId = null)
    {
        return $this->visibles($propietarioId)->whereIn('dui', $this->variantes($dui))->first();
    }

    public function puedeEditar(Padre $padre, ?int $propietarioId): bool
    {
        if (! $propietarioId) {
            return true;
        }
        if ($padre->user_id !== null) {
            return (int) $padre->user_id === $propietarioId;
        }

        return ! $padre->estudiantes()->where('estudiantes.user_id', '!=', $propietarioId)->exists();
    }

    private function visibles(?int $propietarioId)
    {
        return Padre::query()->when($propietarioId, fn ($q) => $q->where(function ($q) use ($propietarioId) {
            $q->where('user_id', $propietarioId)
                ->orWhereHas('estudiantes', fn ($e) => $e->where('estudiantes.user_id', $propietarioId));
        }));
    }

    /** El DUI puede estar guardado con o sin guion en datos antiguos. */
    public function variantes(?string $dui): array
    {
        $normalizado = DuiFormato::normalizar($dui);

        return array_values(array_unique([$normalizado, str_replace('-', '', (string) $normalizado)]));
    }
}
