<?php

namespace App\Services;

use App\Models\Encargado;
use App\Models\Estudiante;
use App\Models\User;
use App\Support\DuiFormato;
use Illuminate\Validation\ValidationException;

/**
 * Encargados (D3, D-E6): familiar responsable que no es el padre ni la madre
 * (parentescos del diagrama). Una persona por DUI, validado en la API.
 * El responsable de la cuenta es uno solo: su registro en `padres` o en
 * `encargados`, con el user_id de la cuenta; nunca ambos.
 * - La cuenta ENCARGADO registra y edita solo su propio perfil (mismo DUI de la cuenta).
 * - El encargado ve su perfil y los encargados de las matrículas de sus estudiantes.
 * - El DIRECTOR gestiona todos; si existe una cuenta ENCARGADO con el mismo DUI, se enlaza sola.
 * Los encargados sin cuenta se crean junto con la matrícula (M6).
 */
class EncargadoService
{
    public function listar(?int $propietarioId = null, ?string $texto = null)
    {
        return $this->visibles($propietarioId)
            ->when(trim((string) $texto) !== '', function ($q) use ($texto) {
                $texto = trim($texto);
                $q->where(fn ($w) => $w->where('nombre', 'like', "%{$texto}%")->orWhere('dui', 'like', "{$texto}%"));
            })
            ->withCount('matriculas')
            ->orderBy('nombre')
            ->get();
    }

    public function obtener($id, ?int $propietarioId = null): Encargado
    {
        return $this->visibles($propietarioId)->withCount('matriculas')->findOrFail($id);
    }

    public function crear(array $data, User $cuenta, ?int $propietarioId): Encargado
    {
        $dui = DuiFormato::normalizar($data['dui']);
        $existente = Encargado::whereIn('dui', $this->variantes($dui))->first();

        if ($existente) {
            throw ValidationException::withMessages(['dui' => 'Ya existe un encargado con este DUI.']);
        }

        if ($propietarioId) {
            // Si la cuenta es el padre o la madre responsable, no se registra otro responsable
            if (\App\Models\Padre::where('user_id', $cuenta->id)->exists()) {
                throw new \DomainException('Usted está registrado como padre o madre: es el responsable de sus estudiantes y no se registran encargados adicionales.');
            }
            if (Encargado::where('user_id', $cuenta->id)->exists()) {
                throw new \DomainException('Ya tiene un perfil de encargado registrado.');
            }

            return Encargado::create($this->campos($data, $dui) + ['user_id' => $cuenta->id]);
        }

        return Encargado::create($this->campos($data, $dui) + ['user_id' => null]);
    }

    public function actualizar($id, array $data, ?int $propietarioId): Encargado
    {
        $encargado = $this->visibles($propietarioId)->findOrFail($id);

        if ($propietarioId && (int) $encargado->user_id !== $propietarioId) {
            throw new \DomainException('Solo la dirección puede modificar los datos de este encargado.');
        }

        $dui = DuiFormato::normalizar($data['dui']);
        if (Encargado::whereIn('dui', $this->variantes($dui))->whereKeyNot($encargado->id)->exists()) {
            throw ValidationException::withMessages(['dui' => 'Ya existe un encargado con este DUI.']);
        }

        $encargado->update($this->campos($data, $dui));

        return $encargado->fresh();
    }

    public function eliminar($id): void
    {
        $encargado = Encargado::findOrFail($id);

        if ($encargado->matriculas()->exists()) {
            throw new \DomainException("No se puede eliminar a {$encargado->nombre}: es responsable de matrículas registradas.");
        }

        $encargado->delete();
    }

    private function visibles(?int $propietarioId)
    {
        return Encargado::query()->when($propietarioId, fn ($q) => $q->where(function ($q) use ($propietarioId) {
            $q->where('user_id', $propietarioId)
                ->orWhereHas('matriculas', fn ($m) => $m->whereIn('estudiante_id', Estudiante::where('user_id', $propietarioId)->select('id')));
        }));
    }

    private function campos(array $data, string $dui): array
    {
        return [
            'nombre' => $data['nombre'],
            'parentesco' => $data['parentesco'],
            'telefono' => $data['telefono'],
            'dui' => $dui,
            'direccion' => $data['direccion'],
        ];
    }

    private function variantes(string $dui): array
    {
        return array_values(array_unique([$dui, str_replace('-', '', $dui)]));
    }
}
