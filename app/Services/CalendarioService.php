<?php

namespace App\Services;

use App\Models\AnioLectivo;
use App\Models\Matricula;
use App\Models\Padre;
use App\Models\PeriodoEscolar;
use App\Models\Seccion;
use App\Support\NombreUnico;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Años lectivos y periodos escolares (M2.2), con las columnas del diagrama ER:
 * anios_lectivos(anio, fecha_inicio, fecha_fin, estado activo/inactivo/cierre)
 * y periodo_escolares(nombre_periodo, nivel = número del periodo, anio_lectivo_id).
 * El año se crea sin periodos; el director los agrega uno a uno (19/10/2026).
 *
 * Toda regla de estado se valida aquí. Una operación que no es posible en el
 * estado actual lanza \DomainException (el controlador responde 409).
 */
class CalendarioService
{
    public const MAX_PERIODOS = 6;

    // --- Consultas ---

    public function listar()
    {
        return AnioLectivo::with('periodos')
            ->orderByRaw("CASE estado WHEN 'activo' THEN 0 WHEN 'inactivo' THEN 1 ELSE 2 END")
            ->orderByDesc('anio')
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

    public function actual(): ?AnioLectivo
    {
        return AnioLectivo::activo()?->load('periodos');
    }

    // --- Años lectivos ---

    public function crear(array $datos): AnioLectivo
    {
        return DB::transaction(function () use ($datos) {
            $anio = AnioLectivo::create([
                'anio' => $datos['anio'],
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
            ]);
            $anio->forceFill(['estado' => AnioLectivo::INACTIVO])->save();

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
                'anio' => $datos['anio'],
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
            if (! $anio->periodos()->exists()) {
                throw new \DomainException('Agregue al menos un periodo escolar antes de activar el año lectivo.');
            }

            // Un solo año activo: lo valida el sistema (el diagrama no tiene un índice para ello)
            $otro = AnioLectivo::where('estado', AnioLectivo::ACTIVO)->lockForUpdate()->first();
            if ($otro) {
                throw new \DomainException("Ya existe un año lectivo activo ({$otro->anio}). Ciérrelo antes de activar otro.");
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

    /**
     * Catálogos que no cambian durante el año (especialidades y secciones,
     * pedido de Josmy del 19/10/2026): con un año activo se rechaza la
     * operación. ":anio" del mensaje se reemplaza por el año activo.
     */
    public function asegurarSinAnioActivo(string $mensaje): void
    {
        if ($activo = AnioLectivo::activo()) {
            throw new \DomainException(str_replace(':anio', (string) $activo->anio, $mensaje));
        }
    }

    // --- Periodos ---

    /** Datos: nombre_periodo y nivel (número del periodo dentro del año, de 1 a MAX_PERIODOS). */
    public function agregarPeriodo($anioId, array $datos): PeriodoEscolar
    {
        return DB::transaction(function () use ($anioId, $datos) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($anioId);

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no admite periodos nuevos.');
            }
            if ($anio->periodos()->count() >= self::MAX_PERIODOS) {
                throw new \DomainException('Un año lectivo admite como máximo '.self::MAX_PERIODOS.' periodos.');
            }
            if ($anio->periodos()->where('nivel', $datos['nivel'])->exists()) {
                throw ValidationException::withMessages(['nivel' => "El año lectivo {$anio->anio} ya tiene un periodo con el nivel {$datos['nivel']}."]);
            }
            $this->asegurarNombrePeriodoLibre($anio, $datos['nombre_periodo']);

            return PeriodoEscolar::create([
                'nombre_periodo' => $datos['nombre_periodo'],
                'nivel' => $datos['nivel'],
                'anio_lectivo_id' => $anio->id,
            ]);
        });
    }

    public function actualizarPeriodo($id, array $datos): PeriodoEscolar
    {
        return DB::transaction(function () use ($id, $datos) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }
            $this->asegurarNombrePeriodoLibre($anio, $datos['nombre_periodo'], $periodo->id);

            $periodo->fill(['nombre_periodo' => $datos['nombre_periodo']]);
            $periodo->save();

            return $periodo;
        });
    }

    public function eliminarPeriodo($id): void
    {
        DB::transaction(function () use ($id) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }
            if ($periodo->tieneRegistrosAcademicos()) {
                throw new \DomainException('No se puede eliminar el periodo porque tiene registros académicos asociados.');
            }

            $periodo->delete();
        });
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): lanzar antes de crear o
     * modificar registros de un periodo.
     */
    public function asegurarPeriodoEditable($periodoId): PeriodoEscolar
    {
        $periodo = PeriodoEscolar::with('anioLectivo')->findOrFail($periodoId);

        if (! $periodo->permiteRegistros()) {
            throw new \DomainException('El año lectivo del periodo no está activo: no se pueden registrar ni modificar notas o conducta.');
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
                ->orderBy('anio')->get(['id', 'anio', 'estado'])->toArray(),
            'anios_sin_periodos' => AnioLectivo::doesntHave('periodos')->orderBy('anio')->get(['id', 'anio', 'estado'])->toArray(),
            'periodos_sin_anio' => PeriodoEscolar::whereNull('anio_lectivo_id')->orWhereNull('nivel')
                ->orderBy('id')->get(['id', 'nombre_periodo', 'nivel', 'anio_lectivo_id'])->toArray(),
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

    /** Nombres trimestrales de la documentación (sugerencia del formulario y datos de prueba). */
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

    // Sin distinguir mayúsculas, tildes ni espacios repetidos
    private function asegurarNombrePeriodoLibre(AnioLectivo $anio, string $nombre, ?int $excepto = null): void
    {
        if (NombreUnico::ocupado($anio->periodos()->when($excepto, fn ($q) => $q->where('id', '<>', $excepto)), 'nombre_periodo', $nombre)) {
            throw ValidationException::withMessages(['nombre_periodo' => "El año lectivo {$anio->anio} ya tiene un periodo llamado «{$nombre}»."]);
        }
    }

    private function anioDelPeriodo(PeriodoEscolar $periodo): AnioLectivo
    {
        if ($periodo->anio_lectivo_id === null || $periodo->nivel === null) {
            throw new \DomainException('Este periodo es anterior a M2.2 y no tiene año lectivo ni número. Revíselo con el diagnóstico antes de usarlo.');
        }

        return AnioLectivo::lockForUpdate()->findOrFail($periodo->anio_lectivo_id);
    }

    private function cambiarEstado($modelo, string $estado): void
    {
        $modelo->forceFill(['estado' => $estado])->save();
    }
}
