<?php

namespace App\Services;

use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * M5: estudiantes. El estudiante pertenece a la cuenta ENCARGADO que lo
 * registra (estudiantes.user_id, tesis pág. 56); el DIRECTOR ve a todos.
 * Con $propietarioId solo se encuentran los estudiantes de esa cuenta y lo
 * ajeno responde como no encontrado.
 * - D-E1: el NIE es opcional, solo dígitos (hasta 10) y único. El encargado lo
 *   corrige hasta que se aprueba la primera matrícula; después, solo el director.
 * - D-E2: un duplicado sin NIE responde 409 sin revelar a qué cuenta pertenece.
 * - D-E3: solo el director pasa un estudiante a otra cuenta ENCARGADO.
 * - D-E7: solo se elimina un estudiante sin matrículas.
 */
class EstudianteService
{
    public const DUPLICADO = 'El estudiante ya está registrado; contacte a la dirección.';

    /** Filtros: q (nombre o NIE), estado y user_id (solo director). */
    public function listar(array $filtros = [], ?int $propietarioId = null)
    {
        return Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
            ->when(! $propietarioId && ! empty($filtros['user_id']), fn ($q) => $q->where('user_id', $filtros['user_id']))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when(trim($filtros['q'] ?? '') !== '', function ($q) use ($filtros) {
                $texto = trim($filtros['q']);
                $q->where(fn ($w) => $w->where('nombre', 'like', "%{$texto}%")->orWhere('nie', 'like', "{$texto}%"));
            })
            ->with([
                'distrito:id,nombre,municipio_id',
                'distrito.municipio:id,nombre,departamento_id',
                'distrito.municipio.departamento:id,nombre',
                'ultimaMatricula' => fn ($q) => $q->select('matriculas.id', 'matriculas.estudiante_id', 'matriculas.estado', 'matriculas.anio_lectivo_id', 'matriculas.seccion_id'),
                'ultimaMatricula.anioLectivo:id,nombre',
                'ultimaMatricula.seccion:id,nombre,grado_id',
                'ultimaMatricula.seccion.grado:id,nombre',
            ])
            ->when(! $propietarioId, fn ($q) => $q->with('user:id,nombre,email'))
            ->withCount(['padres', 'matriculas'])
            ->orderBy('nombre')
            ->get();
    }

    /** Ficha completa: familia, matrículas y permisos de la cuenta que consulta. */
    public function obtener($id, ?int $propietarioId = null): array
    {
        $estudiante = $this->propio($id, $propietarioId)->load([
            'distrito.municipio.departamento',
            'padres:id,nombre,dui,telefono,email,user_id',
            'matriculas' => fn ($q) => $q->orderByDesc('id'),
            'matriculas.anioLectivo:id,nombre,estado',
            'matriculas.seccion:id,nombre,turno,grado_id',
            'matriculas.seccion.grado:id,nombre',
            'matriculas.especialidad:id,nombre',
            'matriculas.encargado:id,nombre,parentesco,telefono',
            'matriculas.enfermedad:id,nombre,medicamento',
            'matriculas.discapacidades:id,nombre',
        ]);

        if (! $propietarioId) {
            $estudiante->load('user:id,nombre,email,dui');
        }

        return [
            'estudiante' => $estudiante,
            'permisos' => [
                'editar_nie' => ! $propietarioId || ! $this->tieneMatriculaAprobada($estudiante),
                'eliminar' => $estudiante->matriculas->isEmpty(),
                'cambiar_cuenta' => ! $propietarioId,
            ],
        ];
    }

    public function crear(array $data): Estudiante
    {
        if ($this->registroDuplicado($data)) {
            throw new \DomainException(self::DUPLICADO);
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
        $estudiante = $this->propio($id, $propietarioId);
        $nie = $data['nie'] ?? null;

        // D-E1: con matrícula aprobada, el NIE solo lo cambia la dirección
        if ($propietarioId && $nie !== $estudiante->nie && $this->tieneMatriculaAprobada($estudiante)) {
            throw ValidationException::withMessages([
                'nie' => 'El estudiante ya tiene una matrícula aprobada: solo la dirección puede cambiar su NIE.',
            ]);
        }

        $estudiante->update([
            'nombre' => $data['nombre'],
            'genero' => $data['genero'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'distrito_id' => $data['distrito_id'],
            'nie' => $nie,
            'direccion' => $data['direccion'],
            'canton' => $data['canton'] ?? null,
        ]);

        return $estudiante->load(['padres', 'distrito']);
    }

    /** D-E7: solo sin matrículas. Se quitan también sus vínculos familiares. */
    public function eliminar($id, ?int $propietarioId, User $autor): void
    {
        $estudiante = $this->propio($id, $propietarioId);

        if ($estudiante->matriculas()->exists()) {
            throw new \DomainException("No se puede eliminar a {$estudiante->nombre}: tiene matrículas registradas.");
        }

        DB::transaction(function () use ($estudiante) {
            $estudiante->padres()->detach();
            $estudiante->delete();
        });

        $this->bitacora('estudiante_eliminado', $autor, ['estudiante_id' => $estudiante->id, 'nombre' => $estudiante->nombre, 'nie' => $estudiante->nie]);
    }

    /** D-E3: el director pasa el estudiante a otra cuenta ENCARGADO activa. */
    public function cambiarCuenta($id, int $userId, User $autor): Estudiante
    {
        $estudiante = Estudiante::with('user:id,nombre,email')->findOrFail($id);
        $nueva = User::find($userId);

        if (! $nueva || ! $nueva->hasRole(User::ROL_ENCARGADO) || $nueva->estado !== User::ESTADO_ACTIVO) {
            throw ValidationException::withMessages(['user_id' => 'La nueva cuenta debe ser una cuenta ENCARGADO activa.']);
        }
        if ((int) $estudiante->user_id === $nueva->id) {
            throw ValidationException::withMessages(['user_id' => 'El estudiante ya pertenece a esa cuenta.']);
        }

        $anterior = $estudiante->user;
        $estudiante->update(['user_id' => $nueva->id]);

        $this->bitacora('cambio_cuenta', $autor, [
            'estudiante_id' => $estudiante->id,
            'estudiante' => $estudiante->nombre,
            'cuenta_anterior' => $anterior ? "{$anterior->id} {$anterior->email}" : null,
            'cuenta_nueva' => "{$nueva->id} {$nueva->email}",
        ]);

        return $estudiante->fresh()->load('user:id,nombre,email');
    }

    public function propio($id, ?int $propietarioId = null): Estudiante
    {
        return Estudiante::query()
            ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
            ->findOrFail($id);
    }

    public function registroDuplicado(array $data): bool
    {
        if (! empty($data['nie'])) {
            return Estudiante::where('nie', $data['nie'])->exists();
        }

        return Estudiante::where('nombre', $data['nombre'])
            ->where('fecha_nacimiento', $data['fecha_nacimiento'])
            ->where('genero', $data['genero'])
            ->where('distrito_id', $data['distrito_id'])
            ->exists();
    }

    private function tieneMatriculaAprobada(Estudiante $estudiante): bool
    {
        return $estudiante->matriculas()->where('estado', 'aprobado')->exists();
    }

    private function bitacora(string $accion, User $autor, array $datos): void
    {
        Log::channel('familias')->info($accion, $datos + ['por' => "{$autor->id} {$autor->email}"]);
    }
}
