<?php

namespace App\Services;

use App\Models\Estudiante;

class EstudianteService
{

    public function crear(array $data): Estudiante
    {
        if ($this->registroDuplicado($data)) {
            throw new \DomainException('El estudiante ya se encuentra registrado.');
        }

        return Estudiante::create([
            'nombre' => $data['nombre'],
            'genero' => $data['genero'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'distrito_id' => $data['distrito_id'],
            'nie' => $data['nie'] ?? null,
            'direccion' => $data['direccion'],
            'canton' => $data['canton'] ?? null,
            'user_id' => $data['user_id'],
        ]);
    }

    public function actualizar($id, array $data, ?int $propietarioId = null)
    {
        $estudiante = Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
            ->findOrFail($id);

        $estudiante->update([
            'nombre' => $data['nombre'],
            'genero' => $data['genero'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'distrito_id' => $data['distrito_id'],
            'nie' => $data['nie'] ?? null,
            'direccion' => $data['direccion'],
            'canton' => $data['canton'] ?? null,
        ]);

        return $estudiante->load(['padres', 'distrito']);
    }

    public function registroDuplicado(array $data): bool
    {
        if (!empty($data['nie'])) {
            return Estudiante::where('nie', $data['nie'])->exists();
        }

        return Estudiante::where('nombre', $data['nombre'])
            ->where('fecha_nacimiento', $data['fecha_nacimiento'])
            ->where('genero', $data['genero'])
            ->where('distrito_id', $data['distrito_id'])
            ->exists();
    }
}
