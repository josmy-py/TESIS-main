<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Años lectivos y periodos escolares (M2.2), con las columnas del diagrama ER:
 * anios_lectivos(nombre, fecha_inicio, fecha_fin, estado activo/inactivo/cierre)
 * y periodo_escolares(nombre, nivel = número del periodo, anio_lectivo_id), más
 * el estado del periodo (pendiente, abierto, cerrado) que exige el documento.
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
            ->orderByRaw("CASE estado WHEN 'activo' THEN 0 WHEN 'inactivo' THEN 1 ELSE 2 END")
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
            $anio->forceFill(['estado' => AnioLectivo::INACTIVO])->save();

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

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }

            $anio->fill([
                'nombre' => $datos['nombre'],
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
            ]);
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
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('Un año lectivo cerrado no puede volver a activarse.');
            }
            if (! $anio->fechasValidas()) {
                throw new \DomainException('Para activar el año lectivo debe tener fecha de inicio y fecha de fin, y la de inicio debe ser anterior.');
            }

            // Un solo año activo: lo valida el sistema (el diagrama no tiene un índice para ello)
            $otro = AnioLectivo::where('estado', AnioLectivo::ACTIVO)->lockForUpdate()->first();
            if ($otro) {
                throw new \DomainException("Ya existe un año lectivo activo ({$otro->nombre}). Ciérrelo antes de activar otro.");
            }

            $this->cambiarEstado($anio, AnioLectivo::ACTIVO);

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
            $sinCerrar = $anio->periodos()->where('estado', '!=', PeriodoEscolar::CERRADO)->orderBy('nivel')->pluck('nombre');
            if ($sinCerrar->isNotEmpty()) {
                throw new \DomainException('Cierre todos los periodos antes de cerrar el año lectivo. Periodos sin cerrar: '.$sinCerrar->implode(', ').'.');
            }

            $this->cambiarEstado($anio, AnioLectivo::CIERRE);

            return $anio->load('periodos');
        });
    }

    public function eliminar($id): void
    {
        DB::transaction(function () use ($id) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($id);

            if ($anio->estado !== AnioLectivo::INACTIVO) {
                throw new \DomainException('Solo puede eliminarse un año lectivo inactivo (que aún no se ha activado).');
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

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no admite periodos nuevos.');
            }

            $numero = (int) $anio->periodos()->max('nivel') + 1;
            if ($numero > self::MAX_PERIODOS) {
                throw new \DomainException('Un año lectivo admite como máximo '.self::MAX_PERIODOS.' periodos.');
            }

            return $this->nuevoPeriodo($anio, $numero, $datos['nombre'] ?? null);
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
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }

            $periodo->fill(['nombre' => $datos['nombre']]);
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

            $this->validarApertura($periodo, $anio);
            $this->cambiarEstado($periodo, PeriodoEscolar::ABIERTO);

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

            $this->cambiarEstado($periodo, PeriodoEscolar::CERRADO);

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

            $this->validarApertura($periodo, $anio);
            $this->cambiarEstado($periodo, PeriodoEscolar::ABIERTO);

            // Sin tabla de auditoría en el diagrama: el motivo queda en el registro de la aplicación
            Log::info('Periodo reabierto', [
                'periodo_id' => $periodo->id,
                'periodo' => $periodo->nombre,
                'motivo' => $motivo,
                'user_id' => auth('api')->id(),
            ]);

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
            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }
            if ($periodo->tieneRegistrosAcademicos()) {
                throw new \DomainException('No se puede eliminar el periodo porque tiene registros académicos asociados.');
            }
            if ($periodo->nivel !== (int) $anio->periodos()->max('nivel')) {
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
        if ($anio->estado === AnioLectivo::CIERRE) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'El año lectivo está cerrado y no admite matrículas.']);
        }

        return $anio;
    }

    /**
     * Año lectivo de una sección (secciones.anio_lectivo_id): debe existir y no
     * estar cerrado.
     */
    public function anioParaSeccion($anioLectivoId): AnioLectivo
    {
        $anio = AnioLectivo::find($anioLectivoId);
        if (! $anio) {
            throw ValidationException::withMessages(['anio_lectivo_id' => 'El año lectivo seleccionado no existe.']);
        }
        if ($anio->estado === AnioLectivo::CIERRE) {
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
            'periodos_sin_anio' => PeriodoEscolar::whereNull('anio_lectivo_id')->orWhereNull('nivel')
                ->orderBy('id')->get(['id', 'nombre', 'nivel', 'anio_lectivo_id'])->toArray(),
            'secciones_sin_anio' => Seccion::whereNull('anio_lectivo_id')->orderBy('id')->get(['id', 'nombre', 'grado_id'])->toArray(),
            'matriculas_sin_anio' => Matricula::whereNull('anio_lectivo_id')->orderBy('id')->get(['id', 'estudiante_id', 'seccion_id'])->toArray(),
            'matriculas_con_otro_anio_que_su_seccion' => Matricula::whereNotNull('seccion_id')->whereNotNull('anio_lectivo_id')->get(['id', 'anio_lectivo_id', 'seccion_id'])
                ->filter(fn ($m) => ($aniosSeccion[$m->seccion_id] ?? null) !== null && (int) $aniosSeccion[$m->seccion_id] !== (int) $m->anio_lectivo_id)
                ->values()->toArray(),
            // Sin cuenta propia ni estudiante vinculado, ningún encargado lo ve: lo vincula el director
            'padres_sin_estudiantes' => Padre::whereNull('user_id')->doesntHave('estudiantes')
                ->orderBy('id')->get(['id', 'nombre', 'dui'])->toArray(),
        ];
    }

    // --- Internos ---

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
            'nivel' => $numero,
        ]);
        $periodo->forceFill(['estado' => PeriodoEscolar::PENDIENTE])->save();

        return $periodo;
    }

    private function anioDelPeriodo(PeriodoEscolar $periodo): AnioLectivo
    {
        if ($periodo->anio_lectivo_id === null || $periodo->nivel === null) {
            throw new \DomainException('Este periodo es anterior a M2.2 y no tiene año lectivo ni número. Revíselo con el diagnóstico antes de usarlo.');
        }

        return AnioLectivo::lockForUpdate()->findOrFail($periodo->anio_lectivo_id);
    }

    private function validarApertura(PeriodoEscolar $periodo, AnioLectivo $anio): void
    {
        if ($anio->estado !== AnioLectivo::ACTIVO) {
            throw new \DomainException('El periodo solo puede abrirse cuando su año lectivo está activo.');
        }

        // Un solo periodo abierto por año: lo valida el sistema
        $otro = PeriodoEscolar::where('anio_lectivo_id', $anio->id)
            ->where('estado', PeriodoEscolar::ABIERTO)
            ->where('id', '<>', $periodo->id)
            ->lockForUpdate()
            ->first();
        if ($otro) {
            throw new \DomainException("Ya hay un periodo abierto en este año ({$otro->nombre}). Ciérrelo primero.");
        }
    }

    private function cambiarEstado($modelo, string $estado): void
    {
        $modelo->forceFill(['estado' => $estado])->save();
    }
}
