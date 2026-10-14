<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M2.4: gestión de secciones (solo DIRECTOR).
 *
 * Reglas aprobadas por Josmy (13/10/2026):
 * - D-S1: el nombre es único por grado y año lectivo, sin importar el turno.
 * - D-S2: un docente es responsable de una sola sección por turno en el mismo año.
 * - D-S3: capacidad de 1 a 50 (límite provisional, Seccion::CAPACIDAD_MAXIMA).
 * - D-S5: no se exige un plan de estudios vigente para crear la sección.
 * Una sección con matrículas o asignaciones no cambia de grado ni de turno, y
 * las secciones de un año cerrado no se modifican ni se eliminan.
 */
class SeccionService
{
    public const CONFLICTO_DUPLICADA = 'La sección ya está registrada para este grado en el año lectivo.';

    /**
     * Filtros: anio_lectivo_id, grado_id y turno. Sin anio_lectivo_id se listan
     * las del año activo; si no hay año activo, todas.
     */
    public function listar(array $filtros = []): array
    {
        $anioId = $filtros['anio_lectivo_id'] ?? AnioLectivo::activo()?->id;

        $secciones = $this->consulta()
            ->when($anioId, fn ($q) => $q->where('anio_lectivo_id', $anioId))
            ->when($filtros['grado_id'] ?? null, fn ($q, $grado) => $q->where('grado_id', $grado))
            ->when($filtros['turno'] ?? null, fn ($q, $turno) => $q->where('turno', $turno))
            ->get();

        return [
            'anio_lectivo_id' => $anioId ? (int) $anioId : null,
            'secciones' => $this->ordenar($secciones)->values(),
        ];
    }

    public function obtener($id): Seccion
    {
        return $this->conOcupacion($this->consulta()->findOrFail($id));
    }

    public function crear(array $datos): Seccion
    {
        $datos['nombre'] = Seccion::normalizarNombre($datos['nombre']);

        return DB::transaction(function () use ($datos) {
            $anio = $this->anioLectivo($datos['anio_lectivo_id']);
            $this->asegurarDocente($datos['profesor_id'], $datos['turno'], $anio->id);
            $this->asegurarNombreLibre($datos, $anio->id);

            $seccion = Seccion::create([
                'nombre' => $datos['nombre'],
                'capacidad' => $datos['capacidad'],
                'turno' => $datos['turno'],
                'anio_lectivo_id' => $anio->id,
                'grado_id' => $datos['grado_id'],
                'profesor_id' => $datos['profesor_id'],
            ]);

            return $this->obtener($seccion->id);
        });
    }

    public function actualizar($id, array $datos): Seccion
    {
        $datos['nombre'] = Seccion::normalizarNombre($datos['nombre']);

        return DB::transaction(function () use ($id, $datos) {
            $seccion = Seccion::with('anioLectivo')->lockForUpdate()->findOrFail($id);

            if ($seccion->anioLectivo?->estado === AnioLectivo::CIERRE) {
                throw ValidationException::withMessages(['anio_lectivo_id' => 'La sección pertenece a un año lectivo cerrado y no puede modificarse.']);
            }

            $anio = $this->anioLectivo($datos['anio_lectivo_id']);
            $tieneMatriculas = $seccion->matriculas()->exists();

            if ($seccion->anio_lectivo_id !== null && $seccion->anio_lectivo_id !== $anio->id && $tieneMatriculas) {
                throw ValidationException::withMessages(['anio_lectivo_id' => 'La sección tiene matrículas y no puede cambiar de año lectivo.']);
            }

            $cambiaGrado = (int) $seccion->grado_id !== (int) $datos['grado_id'];
            $cambiaTurno = $seccion->turno !== $datos['turno'];
            if (($cambiaGrado || $cambiaTurno) && ($tieneMatriculas || $seccion->asignaciones()->exists())) {
                throw new \DomainException('La sección tiene matrículas o asignaciones y no puede cambiar de grado ni de turno.');
            }

            $ocupados = $seccion->matriculas()->whereIn('estado', Seccion::ESTADOS_OCUPAN_CUPO)->count();
            if ((int) $datos['capacidad'] < $ocupados) {
                throw ValidationException::withMessages(['capacidad' => "La capacidad no puede ser menor que los {$ocupados} estudiantes ya matriculados en la sección."]);
            }

            // El docente se revisa solo si cambia el responsable, el turno o el año
            if ((int) $seccion->profesor_id !== (int) $datos['profesor_id'] || $cambiaTurno || $seccion->anio_lectivo_id !== $anio->id) {
                $this->asegurarDocente($datos['profesor_id'], $datos['turno'], $anio->id, $seccion->id);
            }

            $this->asegurarNombreLibre($datos, $anio->id, $seccion->id);

            $seccion->update([
                'nombre' => $datos['nombre'],
                'capacidad' => $datos['capacidad'],
                'turno' => $datos['turno'],
                'anio_lectivo_id' => $anio->id,
                'grado_id' => $datos['grado_id'],
                'profesor_id' => $datos['profesor_id'],
            ]);

            return $this->obtener($seccion->id);
        });
    }

    public function eliminar($id): void
    {
        $seccion = Seccion::with('anioLectivo')->findOrFail($id);

        if ($seccion->anioLectivo?->estado === AnioLectivo::CIERRE) {
            throw new \DomainException('La sección pertenece a un año lectivo cerrado y no puede eliminarse.');
        }
        if ($seccion->matriculas()->exists()) {
            throw new \DomainException('No se puede eliminar la sección porque tiene estudiantes matriculados.');
        }
        if ($seccion->asignaciones()->exists()) {
            throw new \DomainException('No se puede eliminar la sección porque tiene asignaciones de materias.');
        }

        $seccion->delete();
    }

    private function consulta()
    {
        return Seccion::with([
            'grado:id,nombre',
            'profesor:id,nombre,codigo,user_id',
            'anioLectivo:id,nombre,estado',
        ])->withCount([
            'matriculas as matriculados' => fn ($q) => $q->whereIn('estado', Seccion::ESTADOS_OCUPAN_CUPO),
            'matriculas as total_matriculas',
            'asignaciones',
        ]);
    }

    private function conOcupacion(Seccion $seccion): Seccion
    {
        $seccion->setAttribute('cupos_disponibles', max(0, $seccion->capacidad - (int) $seccion->matriculados));

        return $seccion;
    }

    // Orden académico del grado (catálogo oficial), luego turno y nombre
    private function ordenar(Collection $secciones): Collection
    {
        return $secciones
            ->map(fn (Seccion $s) => $this->conOcupacion($s))
            ->sortBy(fn (Seccion $s) => [
                $s->grado?->orden ?? PHP_INT_MAX,
                $s->turno === 'matutino' ? 0 : 1,
                $s->nombre,
            ]);
    }

    private function anioLectivo($anioLectivoId): AnioLectivo
    {
        return app(CalendarioService::class)->anioParaSeccion($anioLectivoId);
    }

    private function asegurarNombreLibre(array $datos, int $anioId, ?int $excepto = null): void
    {
        $existe = Seccion::where('nombre', $datos['nombre'])
            ->where('grado_id', $datos['grado_id'])
            ->where('anio_lectivo_id', $anioId)
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))
            ->exists();

        if ($existe) {
            throw new \DomainException(self::CONFLICTO_DUPLICADA);
        }
    }

    private function asegurarDocente($profesorId, string $turno, int $anioId, ?int $excepto = null): void
    {
        $profesor = Profesor::with('user')->find($profesorId);
        $user = $profesor?->user;

        if (! $user || ! $user->hasRole(User::ROL_DOCENTE)) {
            throw ValidationException::withMessages(['profesor_id' => 'El docente responsable debe tener una cuenta DOCENTE.']);
        }

        $ocupado = Seccion::with('grado:id,nombre')
            ->where('profesor_id', $profesor->id)
            ->where('anio_lectivo_id', $anioId)
            ->where('turno', $turno)
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))
            ->first();

        if ($ocupado) {
            throw new \DomainException("El docente ya es responsable de la sección {$ocupado->grado?->nombre} {$ocupado->nombre} en el turno {$turno} de este año lectivo.");
        }
    }
}
