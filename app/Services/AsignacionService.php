<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Asignacion;
use App\Models\Asignatura;
use App\Models\PlanEstudio;
use App\Models\Profesor;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * M4: asignación académica (tesis, págs. 58, 65 y 68).
 *
 * Una asignación une un docente, una materia (asignatura) y una sección; el año
 * lectivo es el de la sección. Reglas aprobadas por Josmy (13/10/2026):
 * - D-A1: cada sección usa un único plan de estudios vigente de su grado. El
 *   plan de la sección es el de sus materias ya asignadas (sin columnas nuevas).
 * - D-A2: una materia de una sección la imparte un solo docente (API e índice
 *   único seccion_id + asignatura_id).
 * - D-A3: el docente de una asignación puede cambiarse conservando evaluaciones
 *   y notas; el cambio queda registrado en storage/logs/asignaciones-*.log. No
 *   se elimina una asignación con evaluaciones o boletas.
 * - D-A4: años activos e inactivos admiten cambios; los cerrados no. Se
 *   asigna haya o no un año activo (Josmy, 20/10/2026): sin año activo se usa
 *   el año inactivo más reciente.
 * - D-A6: sin límite de materias u horas por docente.
 * DomainException responde 409 y ValidationException 422.
 */
class AsignacionService
{
    /** Filtros: anio_lectivo_id (por defecto el activo o, sin activo, el próximo), profesor_id, seccion_id y grado_id. */
    public function listar(array $filtros = []): array
    {
        $anioId = $filtros['anio_lectivo_id'] ?? $this->anioPredeterminado()?->id;

        $asignaciones = $this->consulta()
            ->when($anioId, fn ($q) => $q->whereHas('seccion', fn ($s) => $s->where('anio_lectivo_id', $anioId)))
            ->when($filtros['profesor_id'] ?? null, fn ($q, $id) => $q->where('profesor_id', $id))
            ->when($filtros['seccion_id'] ?? null, fn ($q, $id) => $q->where('seccion_id', $id))
            ->when($filtros['grado_id'] ?? null, fn ($q, $id) => $q->whereHas('seccion', fn ($s) => $s->where('grado_id', $id)))
            ->get();

        return [
            'anio_lectivo_id' => $anioId ? (int) $anioId : null,
            'asignaciones' => $this->ordenar($asignaciones)->values(),
        ];
    }

    public function obtener($id): Asignacion
    {
        return $this->consulta()->findOrFail($id);
    }

    public function crear(array $datos, ?User $autor = null): Asignacion
    {
        return $this->guardando(function () use ($datos, $autor) {
            $profesor = $this->docenteActivo($datos['profesor_id']);
            $seccion = Seccion::with('anioLectivo', 'grado')->lockForUpdate()->findOrFail($datos['seccion_id']);
            $asignatura = Asignatura::with('planEstudio')->findOrFail($datos['asignatura_id']);

            $asignacion = $this->registrar($profesor, $seccion, $asignatura);
            $this->bitacora('creada', $asignacion, $autor);

            return $this->obtener($asignacion->id);
        });
    }

    /** D-A3: cambia el docente y conserva evaluaciones, boletas y notas. */
    public function cambiarDocente($id, $profesorId, ?User $autor = null): Asignacion
    {
        return $this->guardando(function () use ($id, $profesorId, $autor) {
            $asignacion = Asignacion::with('seccion.anioLectivo', 'profesor')->lockForUpdate()->findOrFail($id);
            $this->asegurarAnioAbierto($asignacion->seccion, 'profesor_id');

            if ((int) $asignacion->profesor_id === (int) $profesorId) {
                throw ValidationException::withMessages(['profesor_id' => 'El docente seleccionado ya imparte esta materia.']);
            }

            $nuevo = $this->docenteActivo($profesorId);
            $anterior = $asignacion->profesor;

            $asignacion->update(['profesor_id' => $nuevo->id]);
            $this->bitacora('cambio_docente', $asignacion, $autor, [
                'profesor_anterior' => $anterior ? "{$anterior->codigo} {$anterior->nombre}" : $asignacion->getOriginal('profesor_id'),
                'profesor_nuevo' => "{$nuevo->codigo} {$nuevo->nombre}",
                'evaluaciones_conservadas' => $asignacion->evaluaciones()->count(),
                'boletas_conservadas' => $asignacion->boletas()->count(),
            ]);

            return $this->obtener($asignacion->id);
        });
    }

    public function eliminar($id, ?User $autor = null): void
    {
        DB::transaction(function () use ($id, $autor) {
            $asignacion = Asignacion::with('seccion.anioLectivo', 'asignatura', 'profesor')->lockForUpdate()->findOrFail($id);

            if ($asignacion->seccion?->anioLectivo?->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('La asignación pertenece a un año lectivo cerrado y no puede eliminarse.');
            }
            $this->asegurarSinRegistros($asignacion);

            $this->bitacora('eliminada', $asignacion, $autor);
            $asignacion->delete();
        });
    }

    /**
     * Guarda de una vez la carga de un docente en un año lectivo (tesis, pág. 65):
     * crea las asignaciones marcadas y elimina las desmarcadas. Todo o nada: si
     * una sola regla falla, no se guarda ningún cambio.
     *
     * @param  array<int, array{seccion_id:int, asignatura_id:int}>  $items
     */
    public function guardarCarga($profesorId, $anioLectivoId, array $items, ?User $autor = null): array
    {
        return $this->guardando(function () use ($profesorId, $anioLectivoId, $items, $autor) {
            $anio = AnioLectivo::findOrFail($anioLectivoId);
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw ValidationException::withMessages(['anio_lectivo_id' => "El año lectivo {$anio->anio} está cerrado y su carga académica no puede modificarse."]);
            }

            // Sin materias marcadas solo se libera la carga; no hace falta que la cuenta siga activa
            $profesor = $items === [] ? Profesor::findOrFail($profesorId) : $this->docenteActivo($profesorId);

            $deseadas = collect($items)
                ->map(fn ($i) => ['seccion_id' => (int) $i['seccion_id'], 'asignatura_id' => (int) $i['asignatura_id']])
                ->unique(fn ($i) => $i['seccion_id'].'-'.$i['asignatura_id'])
                ->keyBy(fn ($i) => $i['seccion_id'].'-'.$i['asignatura_id']);

            $secciones = Seccion::with('anioLectivo', 'grado')->whereIn('id', $deseadas->pluck('seccion_id')->unique())->lockForUpdate()->get()->keyBy('id');
            foreach ($deseadas as $item) {
                $seccion = $secciones->get($item['seccion_id']);
                if (! $seccion || (int) $seccion->anio_lectivo_id !== (int) $anio->id) {
                    throw ValidationException::withMessages(['asignaciones' => "Todas las secciones deben pertenecer al año lectivo {$anio->anio}."]);
                }
            }

            $actuales = Asignacion::with('seccion.grado', 'asignatura', 'profesor')
                ->where('profesor_id', $profesor->id)
                ->whereHas('seccion', fn ($q) => $q->where('anio_lectivo_id', $anio->id))
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($a) => $a->seccion_id.'-'.$a->asignatura_id);

            $quitar = $actuales->diffKeys($deseadas);
            $agregar = $deseadas->diffKeys($actuales);

            // Primero se liberan las desmarcadas: así una sección puede pasar a otro plan
            foreach ($quitar as $asignacion) {
                $this->asegurarSinRegistros($asignacion);
                $this->bitacora('eliminada', $asignacion, $autor);
                $asignacion->delete();
            }

            $asignaturas = Asignatura::with('planEstudio')->whereIn('id', $agregar->pluck('asignatura_id'))->get()->keyBy('id');
            foreach ($agregar as $item) {
                $asignatura = $asignaturas->get($item['asignatura_id']);
                if (! $asignatura) {
                    throw ValidationException::withMessages(['asignaciones' => 'Una de las materias seleccionadas no existe.']);
                }
                $nueva = $this->registrar($profesor, $secciones->get($item['seccion_id']), $asignatura);
                $this->bitacora('creada', $nueva, $autor);
            }

            return [
                'creadas' => $agregar->count(),
                'eliminadas' => $quitar->count(),
                'asignaciones' => $this->listar(['anio_lectivo_id' => $anio->id, 'profesor_id' => $profesor->id])['asignaciones'],
            ];
        });
    }

    /**
     * Carga académica del docente en sesión (tesis, págs. 58 y 68): solo sus
     * asignaciones, del año lectivo activo (o, sin activo, del próximo) salvo
     * que pida otro.
     */
    public function cargaDeDocente(User $user, $anioLectivoId = null): array
    {
        $profesor = $user->profesor;
        $anio = $anioLectivoId ? AnioLectivo::find($anioLectivoId) : $this->anioPredeterminado();

        if (! $profesor || ! $anio) {
            return [
                'profesor' => $profesor?->only(['id', 'codigo', 'nombre']),
                'anio_lectivo' => $anio?->only(['id', 'anio', 'estado']),
                'asignaciones' => [],
                'secciones' => [],
            ];
        }

        $asignaciones = $this->listar(['anio_lectivo_id' => $anio->id, 'profesor_id' => $profesor->id])['asignaciones'];
        $matriculados = Seccion::whereIn('id', $asignaciones->pluck('seccion_id')->unique())
            ->withCount(['matriculas as matriculados' => fn ($q) => $q->where('estado', 'aprobado')])
            ->pluck('matriculados', 'id');

        $asignaciones->each(fn ($a) => $a->seccion->setAttribute('matriculados', (int) ($matriculados[$a->seccion_id] ?? 0)));

        $secciones = $asignaciones->groupBy('seccion_id')->map(fn (Collection $grupo) => [
            'seccion' => $grupo->first()->seccion,
            'plan' => $grupo->first()->asignatura?->planEstudio,
            'materias' => $grupo->map(fn ($a) => ['asignacion_id' => $a->id, 'id' => $a->asignatura_id, 'nombre' => $a->asignatura?->nombre])->values(),
        ])->values();

        return [
            'profesor' => $profesor->only(['id', 'codigo', 'nombre']),
            'anio_lectivo' => $anio->only(['id', 'anio', 'estado']),
            'asignaciones' => $asignaciones,
            'secciones' => $secciones,
        ];
    }

    /** D-A1: plan de estudios que ya usa la sección (el de sus materias asignadas), o null. */
    public function planDeSeccion(int $seccionId): ?PlanEstudio
    {
        $planId = Asignacion::where('seccion_id', $seccionId)
            ->join('asignaturas', 'asignaturas.id', '=', 'asignaciones.asignatura_id')
            ->value('asignaturas.plan_estudio_id');

        return $planId ? PlanEstudio::find($planId) : null;
    }

    /**
     * Revisión de solo lectura de los datos existentes antes del índice único y
     * de las reglas de M4. No modifica nada.
     */
    public function diagnostico(): array
    {
        $duplicadas = DB::table('asignaciones')
            ->select('seccion_id', 'asignatura_id', DB::raw('COUNT(*) as total'), DB::raw('MIN(id) as primera_id'))
            ->groupBy('seccion_id', 'asignatura_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->map(fn ($f) => (array) $f)->all();

        $variosPlanes = DB::table('asignaciones as a')
            ->join('asignaturas as m', 'm.id', '=', 'a.asignatura_id')
            ->select('a.seccion_id', DB::raw('COUNT(DISTINCT m.plan_estudio_id) as planes'))
            ->groupBy('a.seccion_id')
            ->havingRaw('COUNT(DISTINCT m.plan_estudio_id) > 1')
            ->get()->map(fn ($f) => (array) $f)->all();

        return [
            'duplicadas' => $duplicadas,
            'secciones_con_varios_planes' => $variosPlanes,
        ];
    }

    // Año activo; si no hay, el inactivo más reciente (el que se está preparando)
    private function anioPredeterminado(): ?AnioLectivo
    {
        return AnioLectivo::activo()
            ?? AnioLectivo::where('estado', AnioLectivo::INACTIVO)->orderByDesc('anio')->first();
    }

    // --- Reglas ---

    private function registrar(Profesor $profesor, Seccion $seccion, Asignatura $asignatura): Asignacion
    {
        $this->asegurarAnioAbierto($seccion, 'seccion_id');
        $etiqueta = $this->etiquetaSeccion($seccion);
        $plan = $asignatura->planEstudio;

        // La materia debe ser de un plan de estudio vigente
        if (! $plan || ! $plan->estado) {
            throw ValidationException::withMessages(['asignatura_id' => "La materia {$asignatura->nombre} es del plan {$plan?->nombre}, que no está vigente."]);
        }

        // D-A1: un único plan por sección (sin mezclar especialidades)
        $planActual = $this->planDeSeccion($seccion->id);
        if ($planActual && $planActual->id !== $plan->id) {
            throw new \DomainException("La sección {$etiqueta} ya usa el plan {$planActual->nombre}: no se pueden mezclar materias de otro plan o especialidad.");
        }

        // D-A2: un solo docente por materia y sección
        $existente = Asignacion::with('profesor:id,nombre,codigo')
            ->where('seccion_id', $seccion->id)->where('asignatura_id', $asignatura->id)->first();
        if ($existente) {
            throw new \DomainException((int) $existente->profesor_id === $profesor->id
                ? "La materia {$asignatura->nombre} de {$etiqueta} ya está asignada a este docente."
                : "La materia {$asignatura->nombre} de {$etiqueta} ya la imparte {$existente->profesor?->nombre}.");
        }

        return Asignacion::create([
            'profesor_id' => $profesor->id,
            'asignatura_id' => $asignatura->id,
            'seccion_id' => $seccion->id,
        ]);
    }

    private function docenteActivo($profesorId): Profesor
    {
        $profesor = Profesor::with('user')->find($profesorId);
        $user = $profesor?->user;

        if (! $user || ! $user->hasRole(User::ROL_DOCENTE)) {
            throw ValidationException::withMessages(['profesor_id' => 'El docente debe tener una cuenta DOCENTE.']);
        }

        return $profesor;
    }

    private function asegurarAnioAbierto(?Seccion $seccion, string $campo): void
    {
        if ($seccion?->anioLectivo?->estado === AnioLectivo::CIERRE) {
            throw ValidationException::withMessages([$campo => "La sección {$this->etiquetaSeccion($seccion)} pertenece a un año lectivo cerrado: su carga académica no puede modificarse."]);
        }
    }

    // Quitar una asignación con evaluaciones o boletas borraría registros académicos
    private function asegurarSinRegistros(Asignacion $asignacion): void
    {
        if ($asignacion->evaluaciones()->exists() || $asignacion->boletas()->exists()) {
            $asignacion->loadMissing('asignatura', 'seccion.grado');
            throw new \DomainException("No se puede quitar {$asignacion->asignatura?->nombre} de {$this->etiquetaSeccion($asignacion->seccion)} porque ya tiene evaluaciones o notas. Puede cambiar el docente en su lugar.");
        }
    }

    // El índice único resuelve las carreras entre dos guardados simultáneos
    private function guardando(callable $operacion)
    {
        try {
            return DB::transaction($operacion);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000' || str_contains($e->getMessage(), 'UNIQUE')) {
                throw new \DomainException('Otra asignación con la misma materia y sección se guardó al mismo tiempo. Actualice la página e intente de nuevo.');
            }
            throw $e;
        }
    }

    private function etiquetaSeccion(?Seccion $seccion): string
    {
        return trim(($seccion?->grado?->nombre ?? 'Sección').' '.($seccion?->nombre ?? ''));
    }

    // D-A3: trazabilidad de los cambios sin tablas fuera del diagrama
    private function bitacora(string $accion, Asignacion $asignacion, ?User $autor, array $extra = []): void
    {
        $asignacion->loadMissing('seccion.grado', 'seccion.anioLectivo', 'asignatura', 'profesor');

        Log::channel('asignaciones')->info($accion, $extra + [
            'asignacion_id' => $asignacion->id,
            'anio_lectivo' => $asignacion->seccion?->anioLectivo?->anio,
            'seccion' => $this->etiquetaSeccion($asignacion->seccion),
            'materia' => $asignacion->asignatura?->nombre,
            'docente' => $asignacion->profesor ? "{$asignacion->profesor->codigo} {$asignacion->profesor->nombre}" : null,
            'por' => $autor ? "{$autor->id} {$autor->email}" : 'sistema',
        ]);
    }

    private function consulta()
    {
        return Asignacion::with([
            'profesor:id,nombre,codigo,user_id',
            'asignatura:id,nombre,plan_estudio_id',
            'asignatura.planEstudio:id,nombre,especialidad_id,estado',
            'asignatura.planEstudio.especialidad:id,nombre',
            'seccion:id,nombre,turno,capacidad,grado_id,anio_lectivo_id,profesor_id',
            'seccion.grado:id,nombre',
            'seccion.anioLectivo:id,anio,estado',
        ])->withCount(['evaluaciones', 'boletas']);
    }

    // Orden académico del grado, turno, sección y materia
    private function ordenar(Collection $asignaciones): Collection
    {
        return $asignaciones->sortBy(fn (Asignacion $a) => [
            $a->seccion?->grado?->orden ?? PHP_INT_MAX,
            $a->seccion?->turno === 'matutino' ? 0 : 1,
            $a->seccion?->nombre,
            $a->asignatura?->nombre,
        ]);
    }
}
