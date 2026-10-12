<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\AuditoriaCalendario;
use App\Models\Matricula;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Años lectivos y periodos escolares (M2.2).
 *
 * Toda regla de estado se valida aquí. Una operación que no es posible en el
 * estado actual lanza \DomainException (el controlador responde 409).
 */
class CalendarioService
{
    public const PERIODOS_POR_DEFECTO = 3;
    public const MAX_PERIODOS = 6;

    // --- Consultas ---

    public function listar()
    {
        return AnioLectivo::with('periodos')
            ->orderByRaw("CASE estado WHEN 'activo' THEN 0 WHEN 'planificado' THEN 1 ELSE 2 END")
            ->orderByDesc('nombre')
            ->get();
    }

    public function obtener($id): AnioLectivo
    {
        return AnioLectivo::with('periodos')->findOrFail($id);
    }

    public function periodos($anioId)
    {
        return AnioLectivo::findOrFail($anioId)->periodos()->get();
    }

    /** @return array{0: ?AnioLectivo, 1: ?PeriodoEscolar} */
    public function actual(): array
    {
        $anio = AnioLectivo::activo();
        $periodo = $anio
            ? PeriodoEscolar::where('anio_lectivo_id', $anio->id)->where('estado', PeriodoEscolar::ABIERTO)->first()
            : null;

        return [$anio, $periodo];
    }

    public function historial($anioId)
    {
        $anio = AnioLectivo::findOrFail($anioId);
        $periodos = PeriodoEscolar::where('anio_lectivo_id', $anio->id)->pluck('id');

        return AuditoriaCalendario::with('usuario:id,nombre,email')
            ->where(function ($q) use ($anio, $periodos) {
                $q->where(fn ($q) => $q->where('entidad', 'anio_lectivo')->where('entidad_id', $anio->id))
                    ->orWhere(fn ($q) => $q->where('entidad', 'periodo_escolar')->whereIn('entidad_id', $periodos));
            })
            ->orderByDesc('id')
            ->get();
    }

    // --- Años lectivos ---

    public function crear(array $datos): AnioLectivo
    {
        $cantidad = (int) ($datos['cantidad_periodos'] ?? self::PERIODOS_POR_DEFECTO);

        return DB::transaction(function () use ($datos, $cantidad) {
            $anio = AnioLectivo::create([
                'nombre' => $datos['nombre'],
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
            ]);
            $anio->forceFill(['estado' => AnioLectivo::PLANIFICADO])->save();

            for ($numero = 1; $numero <= $cantidad; $numero++) {
                $this->nuevoPeriodo($anio, $numero);
            }

            return $anio->load('periodos');
        });
    }

    public function actualizar($id, array $datos): AnioLectivo
    {
        return DB::transaction(function () use ($id, $datos) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($id);

            if ($anio->estado === AnioLectivo::CERRADO) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }

            $anio->fill([
                'nombre' => $datos['nombre'],
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
            ]);

            foreach ($anio->periodos as $periodo) {
                if ($periodo->fecha_inicio && $periodo->fecha_fin && ! $this->dentroDelAnio($periodo->fecha_inicio, $periodo->fecha_fin, $anio)) {
                    throw new \DomainException("Las fechas de \"{$periodo->nombre}\" quedarían fuera del año lectivo. Ajuste primero el periodo.");
                }
            }

            $anio->save();

            return $anio->load('periodos');
        });
    }

    public function activar($id): AnioLectivo
    {
        return DB::transaction(function () use ($id) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($id);

            if ($anio->estado === AnioLectivo::ACTIVO) {
                throw new \DomainException('El año lectivo ya está activo.');
            }
            if ($anio->estado === AnioLectivo::CERRADO) {
                throw new \DomainException('Un año lectivo cerrado no puede volver a activarse.');
            }
            if (! $anio->fechasValidas()) {
                throw new \DomainException('Para activar el año lectivo debe tener fecha de inicio y fecha de fin, y la de inicio debe ser anterior.');
            }

            $otro = AnioLectivo::where('estado', AnioLectivo::ACTIVO)->lockForUpdate()->first();
            if ($otro) {
                throw new \DomainException("Ya existe un año lectivo activo ({$otro->nombre}). Ciérrelo antes de activar otro.");
            }

            $this->cambiarEstado($anio, 'anio_lectivo', 'activar', AnioLectivo::ACTIVO, ['activo_unico' => true]);

            return $anio->load('periodos');
        });
    }

    public function cerrar($id): AnioLectivo
    {
        return DB::transaction(function () use ($id) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($id);

            if ($anio->estado !== AnioLectivo::ACTIVO) {
                throw new \DomainException('Solo puede cerrarse el año lectivo activo.');
            }
            // Todos los periodos deben estar cerrados: uno pendiente o abierto
            // quedaría imposible de completar con el año ya cerrado
            $sinCerrar = $anio->periodos()->where('estado', '!=', PeriodoEscolar::CERRADO)->orderBy('numero')->pluck('nombre');
            if ($sinCerrar->isNotEmpty()) {
                throw new \DomainException('Cierre todos los periodos antes de cerrar el año lectivo. Periodos sin cerrar: '.$sinCerrar->implode(', ').'.');
            }

            $this->cambiarEstado($anio, 'anio_lectivo', 'cerrar', AnioLectivo::CERRADO, ['activo_unico' => null]);

            return $anio->load('periodos');
        });
    }

    public function eliminar($id): void
    {
        DB::transaction(function () use ($id) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($id);

            if ($anio->estado !== AnioLectivo::PLANIFICADO) {
                throw new \DomainException('Solo puede eliminarse un año lectivo planificado.');
            }
            if ($anio->secciones()->exists() || $anio->matriculas()->exists()) {
                throw new \DomainException('No se puede eliminar el año lectivo porque tiene secciones o matrículas asociadas.');
            }
            foreach ($anio->periodos as $periodo) {
                if ($periodo->tieneRegistrosAcademicos()) {
                    throw new \DomainException('No se puede eliminar el año lectivo porque sus periodos tienen registros académicos.');
                }
            }

            $anio->periodos()->delete();
            $anio->delete();
        });
    }

    // --- Periodos ---

    public function agregarPeriodo($anioId, array $datos): PeriodoEscolar
    {
        return DB::transaction(function () use ($anioId, $datos) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($anioId);

            if ($anio->estado === AnioLectivo::CERRADO) {
                throw new \DomainException('El año lectivo está cerrado y no admite periodos nuevos.');
            }

            $numero = (int) $anio->periodos()->max('numero') + 1;
            if ($numero > self::MAX_PERIODOS) {
                throw new \DomainException('Un año lectivo admite como máximo '.self::MAX_PERIODOS.' periodos.');
            }

            $periodo = $this->nuevoPeriodo($anio, $numero, $datos['nombre'] ?? null);

            if (! empty($datos['fecha_inicio']) || ! empty($datos['fecha_fin'])) {
                $periodo->fill(['fecha_inicio' => $datos['fecha_inicio'] ?? null, 'fecha_fin' => $datos['fecha_fin'] ?? null]);
                $this->validarFechasPeriodo($periodo, $anio);
                $periodo->save();
            }

            return $periodo;
        });
    }

    public function actualizarPeriodo($id, array $datos): PeriodoEscolar
    {
        return DB::transaction(function () use ($id, $datos) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($periodo->estado !== PeriodoEscolar::PENDIENTE) {
                throw new \DomainException('Solo pueden editarse periodos pendientes.');
            }
            if ($anio->estado === AnioLectivo::CERRADO) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }

            $periodo->fill([
                'nombre' => $datos['nombre'],
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
            ]);
            $this->validarFechasPeriodo($periodo, $anio);
            $periodo->save();

            return $periodo;
        });
    }

    public function abrirPeriodo($id): PeriodoEscolar
    {
        return DB::transaction(function () use ($id) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($periodo->estado === PeriodoEscolar::ABIERTO) {
                throw new \DomainException('El periodo ya está abierto.');
            }
            if ($periodo->estado === PeriodoEscolar::CERRADO) {
                throw new \DomainException('El periodo está cerrado. Para volver a abrirlo use "reabrir" e indique el motivo.');
            }

            $this->validarApertura($periodo, $anio, true);
            $this->cambiarEstado($periodo, 'periodo_escolar', 'abrir', PeriodoEscolar::ABIERTO, ['abierto_en' => $anio->id]);

            return $periodo;
        });
    }

    public function cerrarPeriodo($id): PeriodoEscolar
    {
        return DB::transaction(function () use ($id) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);

            if ($periodo->estado !== PeriodoEscolar::ABIERTO) {
                throw new \DomainException('Solo puede cerrarse un periodo abierto.');
            }

            $this->cambiarEstado($periodo, 'periodo_escolar', 'cerrar', PeriodoEscolar::CERRADO, ['abierto_en' => null]);

            return $periodo;
        });
    }

    public function reabrirPeriodo($id, string $motivo): PeriodoEscolar
    {
        return DB::transaction(function () use ($id, $motivo) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($periodo->estado !== PeriodoEscolar::CERRADO) {
                throw new \DomainException('Solo puede reabrirse un periodo cerrado.');
            }

            $this->validarApertura($periodo, $anio, false);
            $this->cambiarEstado($periodo, 'periodo_escolar', 'reabrir', PeriodoEscolar::ABIERTO, ['abierto_en' => $anio->id], $motivo);

            return $periodo;
        });
    }

    public function eliminarPeriodo($id): void
    {
        DB::transaction(function () use ($id) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($periodo->estado !== PeriodoEscolar::PENDIENTE) {
                throw new \DomainException('Solo pueden eliminarse periodos pendientes.');
            }
            if ($anio->estado === AnioLectivo::CERRADO) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }
            if ($periodo->tieneRegistrosAcademicos()) {
                throw new \DomainException('No se puede eliminar el periodo porque tiene registros académicos asociados.');
            }
            if ($periodo->numero !== (int) $anio->periodos()->max('numero')) {
                throw new \DomainException('Solo puede eliminarse el último periodo del año, para conservar la numeración.');
            }

            $periodo->delete();
        });
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): lanzar antes de crear o
     * modificar registros de un periodo.
     */
    public function asegurarPeriodoAbierto($periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::with('anioLectivo')->findOrFail($periodoId);

        if (! $periodo->permiteRegistros()) {
            throw new \DomainException('El periodo no está abierto: no se pueden registrar ni modificar notas o conducta.');
        }

        return $periodo;
    }

    // --- Año lectivo para secciones y matrículas ---

    /**
     * Año lectivo de una matrícula nueva: el indicado (activo o planificado) o,
     * si no se indica, el activo.
     */
    public function anioParaMatricula($anioLectivoId = null): AnioLectivo
    {
        $anio = $anioLectivoId ? AnioLectivo::find($anioLectivoId) : AnioLectivo::activo();

        if (! $anio) {
            throw ValidationException::withMessages([
                'anio_lectivo_id' => $anioLectivoId
                    ? 'El año lectivo seleccionado no existe.'
                    : 'No hay un año lectivo activo. Indique el año lectivo de la matrícula.',
            ]);
        }
        if ($anio->estado === AnioLectivo::CERRADO) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'El año lectivo está cerrado y no admite matrículas.']);
        }

        return $anio;
    }

    /**
     * Año lectivo de una sección: por id o, durante la transición, por el año
     * calendario del campo antiguo "anio" cuando hay un único año lectivo con
     * ese nombre.
     */
    public function anioParaSeccion($anioLectivoId = null, $anioCalendario = null): AnioLectivo
    {
        if ($anioLectivoId) {
            $anio = AnioLectivo::find($anioLectivoId);
            if (! $anio) {
                throw ValidationException::withMessages(['anio_lectivo_id' => 'El año lectivo seleccionado no existe.']);
            }
            if ($anioCalendario && (string) $anioCalendario !== $anio->anioCalendario()) {
                throw ValidationException::withMessages(['anio' => 'El año no coincide con el año lectivo seleccionado.']);
            }
        } else {
            $anio = AnioLectivo::where('nombre', (string) $anioCalendario)->first();
            if (! $anio) {
                throw ValidationException::withMessages(['anio_lectivo_id' => "No existe un año lectivo para {$anioCalendario}. Créelo primero o indique el año lectivo."]);
            }
        }

        if ($anio->estado === AnioLectivo::CERRADO) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'El año lectivo está cerrado y no admite cambios en sus secciones.']);
        }

        return $anio;
    }

    // --- Diagnóstico de datos anteriores ---

    public function diagnostico(): array
    {
        $aniosSeccion = Seccion::pluck('anio_lectivo_id', 'id');

        return [
            'anios_sin_fechas' => AnioLectivo::where(fn ($q) => $q->whereNull('fecha_inicio')->orWhereNull('fecha_fin'))
                ->orderBy('nombre')->get(['id', 'nombre', 'estado'])->toArray(),
            'anios_sin_periodos' => AnioLectivo::doesntHave('periodos')->orderBy('nombre')->get(['id', 'nombre', 'estado'])->toArray(),
            'periodos_sin_anio' => PeriodoEscolar::whereNull('anio_lectivo_id')->orWhereNull('numero')
                ->orderBy('id')->get(['id', 'nombre', 'nivel', 'anio_lectivo_id', 'numero'])->toArray(),
            'secciones_sin_anio' => Seccion::whereNull('anio_lectivo_id')->orderBy('id')->get(['id', 'nombre', 'anio', 'grado_id'])->toArray(),
            'matriculas_sin_anio' => Matricula::whereNull('anio_lectivo_id')->orderBy('id')->get(['id', 'anio', 'estudiante_id', 'seccion_id'])->toArray(),
            'matriculas_con_otro_anio_que_su_seccion' => Matricula::whereNotNull('seccion_id')->whereNotNull('anio_lectivo_id')->get(['id', 'anio', 'anio_lectivo_id', 'seccion_id'])
                ->filter(fn ($m) => ($aniosSeccion[$m->seccion_id] ?? null) !== null && (int) $aniosSeccion[$m->seccion_id] !== (int) $m->anio_lectivo_id)
                ->values()->toArray(),
            'anios_solo_en_asignaciones' => $this->aniosSoloEnAsignaciones(),
            'asignaciones_sin_anio_lectivo' => DB::table('asignaciones')
                ->leftJoin('anios_lectivos', 'anios_lectivos.nombre', '=', DB::raw('CAST(asignaciones.anio AS CHAR(4))'))
                ->whereNull('anios_lectivos.id')
                ->orderBy('asignaciones.id')
                ->get(['asignaciones.id', 'asignaciones.anio', 'asignaciones.seccion_id'])
                ->map(fn ($fila) => (array) $fila)->toArray(),
            'asignaciones_con_otro_anio_que_su_seccion' => DB::table('asignaciones')
                ->join('secciones', 'secciones.id', '=', 'asignaciones.seccion_id')
                ->whereColumn('asignaciones.anio', '<>', 'secciones.anio')
                ->orderBy('asignaciones.id')
                ->get(['asignaciones.id', 'asignaciones.anio', 'asignaciones.seccion_id', 'secciones.anio as anio_seccion'])
                ->map(fn ($fila) => (array) $fila)->toArray(),
        ];
    }

    // --- Internos ---

    /**
     * Años que solo aparecen en asignaciones.anio (en ninguna sección ni
     * matrícula), con el año lectivo creado para ellos si existe. Se
     * conservan tal cual para reconciliarlos a mano.
     */
    private function aniosSoloEnAsignaciones(): array
    {
        $texto = fn ($anio) => trim((string) $anio);
        $porAnio = DB::table('asignaciones')->pluck('anio')->map($texto)->countBy();
        $usados = DB::table('secciones')->pluck('anio')->merge(DB::table('matriculas')->pluck('anio'))->map($texto)->unique();
        $lectivos = AnioLectivo::whereIn('nombre', $porAnio->keys()->all())->get(['id', 'nombre', 'estado'])->keyBy('nombre');

        return $porAnio->keys()->diff($usados)->sort()->values()
            ->map(fn ($anio) => [
                'anio' => (string) $anio,
                'anio_lectivo_id' => $lectivos[$anio]->id ?? null,
                'estado' => $lectivos[$anio]->estado ?? null,
                'asignaciones' => $porAnio[$anio],
            ])->all();
    }

    /** Nombres trimestrales de la documentación; los periodos adicionales siguen la misma forma ordinal. */
    public static function nombrePredeterminado(int $numero): string
    {
        return match ($numero) {
            1 => 'Primer trimestre',
            2 => 'Segundo trimestre',
            3 => 'Tercer trimestre',
            4 => 'Cuarto periodo',
            5 => 'Quinto periodo',
            6 => 'Sexto periodo',
            default => "Periodo {$numero}",
        };
    }

    private function nuevoPeriodo(AnioLectivo $anio, int $numero, ?string $nombre = null): PeriodoEscolar
    {
        $periodo = new PeriodoEscolar([
            'nombre' => $nombre ?: self::nombrePredeterminado($numero),
            'anio_lectivo_id' => $anio->id,
            'numero' => $numero,
        ]);
        $periodo->forceFill(['estado' => PeriodoEscolar::PENDIENTE])->save();

        return $periodo;
    }

    private function anioDelPeriodo(PeriodoEscolar $periodo): AnioLectivo
    {
        if ($periodo->anio_lectivo_id === null || $periodo->numero === null) {
            throw new \DomainException('Este periodo es anterior a M2.2 y no tiene año lectivo ni número. Revíselo con el diagnóstico antes de usarlo.');
        }

        return AnioLectivo::lockForUpdate()->findOrFail($periodo->anio_lectivo_id);
    }

    private function validarApertura(PeriodoEscolar $periodo, AnioLectivo $anio, bool $exigirFechas): void
    {
        if ($anio->estado !== AnioLectivo::ACTIVO) {
            throw new \DomainException('El periodo solo puede abrirse cuando su año lectivo está activo.');
        }
        if ($exigirFechas && (! $periodo->fechasValidas() || ! $this->dentroDelAnio($periodo->fecha_inicio, $periodo->fecha_fin, $anio))) {
            throw new \DomainException('Para abrir el periodo debe tener fechas válidas dentro del año lectivo.');
        }

        $otro = PeriodoEscolar::where('anio_lectivo_id', $anio->id)
            ->where('estado', PeriodoEscolar::ABIERTO)
            ->where('id', '<>', $periodo->id)
            ->first();
        if ($otro) {
            throw new \DomainException("Ya hay un periodo abierto en este año ({$otro->nombre}). Ciérrelo primero.");
        }
    }

    private function validarFechasPeriodo(PeriodoEscolar $periodo, AnioLectivo $anio): void
    {
        $inicio = $periodo->fecha_inicio;
        $fin = $periodo->fecha_fin;

        if ($inicio === null && $fin === null) {
            return;
        }
        if ($inicio === null || $fin === null || ! $inicio->lt($fin)) {
            throw ValidationException::withMessages(['fecha_fin' => 'Indique ambas fechas; la de fin debe ser posterior a la de inicio.']);
        }
        if (! $anio->fechasValidas()) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Complete primero las fechas del año lectivo.']);
        }
        if (! $this->dentroDelAnio($inicio, $fin, $anio)) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Las fechas del periodo deben estar dentro de las fechas del año lectivo.']);
        }

        $otros = PeriodoEscolar::where('anio_lectivo_id', $anio->id)->where('id', '<>', $periodo->id ?? 0)
            ->whereNotNull('fecha_inicio')->whereNotNull('fecha_fin')->get();

        foreach ($otros as $otro) {
            $antes = $otro->numero < $periodo->numero;
            if (($antes && ! $otro->fecha_fin->lt($inicio)) || (! $antes && ! $fin->lt($otro->fecha_inicio))) {
                throw ValidationException::withMessages([
                    'fecha_inicio' => "Las fechas se superponen con \"{$otro->nombre}\" o no respetan el orden de los periodos.",
                ]);
            }
        }
    }

    private function dentroDelAnio(Carbon $inicio, Carbon $fin, AnioLectivo $anio): bool
    {
        if ($anio->fecha_inicio === null || $anio->fecha_fin === null) {
            return false;
        }

        return $inicio->gte($anio->fecha_inicio) && $fin->lte($anio->fecha_fin);
    }

    private function cambiarEstado($modelo, string $entidad, string $accion, string $estado, array $extra = [], ?string $motivo = null): void
    {
        $anterior = $modelo->estado;
        $modelo->forceFill(['estado' => $estado] + $extra)->save();

        AuditoriaCalendario::create([
            'entidad' => $entidad,
            'entidad_id' => $modelo->id,
            'accion' => $accion,
            'estado_anterior' => $anterior,
            'estado_nuevo' => $estado,
            'motivo' => $motivo,
            'user_id' => auth('api')->id(),
        ]);
    }
}
