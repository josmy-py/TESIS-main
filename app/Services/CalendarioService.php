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
 * y periodo_escolares(nombre, nivel académico, anio_lectivo_id,
 * estado pendiente/activo/cerrado).
 * El año se crea sin periodos; el director los agrega uno a uno y administra
 * su estado para cerrar cada trimestre o periodo (19/10/2026).
 *
 * Toda regla de estado se valida aquí. Una operación que no es posible en el
 * estado actual lanza \DomainException (el controlador responde 409).
 */
class CalendarioService
{
    // Máximo de periodos de cada nivel académico en un año lectivo
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
                'inicio_matricula' => $datos['inicio_matricula'] ?? null,
                'fin_matricula' => $datos['fin_matricula'] ?? null,
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
                'inicio_matricula' => $datos['inicio_matricula'] ?? $anio->inicio_matricula,
                'fin_matricula' => $datos['fin_matricula'] ?? $anio->fin_matricula,
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
            $abiertos = $anio->periodos()->where('estado', '<>', PeriodoEscolar::CERRADO)->get()
                ->map(fn (PeriodoEscolar $p) => "{$p->nombre} ({$p->nivel_nombre})");
            if ($abiertos->isNotEmpty()) {
                throw new \DomainException('Cierre todos los periodos antes de cerrar el año lectivo. Falta: '.$abiertos->implode(', ').'.');
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
     * Catálogos que no cambian durante el año (especialidades, pedido de Josmy
     * del 19/10/2026, ajustado el 20/10/2026): se rechaza la operación solo si
     * el año activo ya comenzó (llegó su fecha de inicio). Un año activo cuyas
     * fechas aún no comienzan sí admite cambios. ":anio" y ":fecha" del mensaje
     * se reemplazan por el año activo y su fecha de inicio.
     */
    public function asegurarAnioActivoSinComenzar(string $mensaje): void
    {
        $activo = AnioLectivo::activo();
        if ($activo && $activo->haComenzado()) {
            throw new \DomainException(self::mensajeAnio($mensaje, $activo));
        }
    }

    /**
     * Secciones (pedido de Josmy, 20/10/2026): las de un año lectivo se crean,
     * modifican o eliminan mientras ese año no haya comenzado, esté activo o no.
     */
    public function asegurarAnioSinComenzar(AnioLectivo $anio, string $mensaje): void
    {
        if ($anio->haComenzado()) {
            throw new \DomainException(self::mensajeAnio($mensaje, $anio));
        }
    }

    private static function mensajeAnio(string $mensaje, AnioLectivo $anio): string
    {
        return strtr($mensaje, [':anio' => (string) $anio->anio, ':fecha' => $anio->fecha_inicio?->format('d/m/Y') ?? '']);
    }

    // --- Periodos ---

    /**
     * Datos: nombre y nivel académico (1 = Parvularia y Educación Básica,
     * 2 = Bachillerato; pedido de Josmy del 23/10/2026). Cada nivel tiene sus
     * propios periodos: el nombre no se repite dentro del mismo nivel y año.
     */
    public function agregarPeriodo($anioId, array $datos): PeriodoEscolar
    {
        return DB::transaction(function () use ($anioId, $datos) {
            $anio = AnioLectivo::lockForUpdate()->findOrFail($anioId);

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no admite periodos nuevos.');
            }
            if ($anio->periodos()->where('nivel', $datos['nivel'])->count() >= self::MAX_PERIODOS) {
                throw new \DomainException('Un año lectivo admite como máximo '.self::MAX_PERIODOS.' periodos de nivel '.(int) $datos['nivel'].'.');
            }
            $this->asegurarNombrePeriodoLibre($anio, $datos['nombre'], (int) $datos['nivel']);

            return PeriodoEscolar::create([
                'nombre' => $datos['nombre'],
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
            // Solo se edita el nombre: el nivel académico queda fijo al crear el periodo
            $this->asegurarNombrePeriodoLibre($anio, $datos['nombre'], (int) $periodo->nivel, $periodo->id);

            $periodo->fill(['nombre' => $datos['nombre']]);
            $periodo->save();

            return $periodo;
        });
    }

    /**
     * Estado del periodo (pedido de Josmy, 19/10/2026):
     * - activo: desde pendiente, o para reabrir uno cerrado. Solo con el año
     *   activo y sin otro periodo activo de su nivel académico en ese año.
     * - cerrado: desde activo; cierra el trimestre o periodo (ya no se
     *   registran notas ni conducta en él).
     * - pendiente: desde activo, si se activó por error.
     */
    public function cambiarEstadoPeriodo($id, string $estado): PeriodoEscolar
    {
        return DB::transaction(function () use ($id, $estado) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($periodo->estado === $estado) {
                throw new \DomainException("El periodo {$periodo->nombre} ya está ".self::textoEstadoPeriodo($estado).'.');
            }
            if ($anio->estado !== AnioLectivo::ACTIVO) {
                throw new \DomainException("El año lectivo {$anio->anio} no está activo: no se puede cambiar el estado de sus periodos.");
            }
            if ($estado === PeriodoEscolar::ACTIVO) {
                $otro = $anio->periodos()->where('nivel', $periodo->nivel)->where('estado', PeriodoEscolar::ACTIVO)->whereKeyNot($periodo->id)->first();
                if ($otro) {
                    throw new \DomainException("{$otro->nombre} sigue activo: ciérrelo antes de iniciar otro periodo.");
                }
            } elseif ($periodo->estado !== PeriodoEscolar::ACTIVO) {
                throw new \DomainException($estado === PeriodoEscolar::CERRADO
                    ? 'Solo se puede cerrar un periodo activo.'
                    : 'Un periodo cerrado no vuelve a pendiente: si hace falta, actívelo de nuevo.');
            }

            $this->cambiarEstado($periodo, $estado);

            return $periodo;
        });
    }

    public static function textoEstadoPeriodo(string $estado): string
    {
        return match ($estado) {
            PeriodoEscolar::ACTIVO => 'activo',
            PeriodoEscolar::CERRADO => 'cerrado',
            default => 'pendiente',
        };
    }

    public function eliminarPeriodo($id): void
    {
        DB::transaction(function () use ($id) {
            $periodo = PeriodoEscolar::lockForUpdate()->findOrFail($id);
            $anio = $this->anioDelPeriodo($periodo);

            if ($anio->estado === AnioLectivo::CIERRE) {
                throw new \DomainException('El año lectivo está cerrado y no puede modificarse.');
            }
            if ($periodo->estado !== PeriodoEscolar::PENDIENTE) {
                throw new \DomainException('Solo se puede eliminar un periodo pendiente.');
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
            throw new \DomainException($periodo->motivoBloqueo('no se pueden registrar ni modificar notas o conducta'));
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
     * Pedido de Josmy (20/10/2026): una matrícula nueva solo se solicita entre
     * inicio_matricula y fin_matricula del año lectivo (ambos días incluidos).
     */
    public function asegurarPeriodoDeMatricula(AnioLectivo $anio): void
    {
        if ($motivo = $anio->motivoMatriculaCerrada()) {
            throw new \DomainException($motivo);
        }
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

    // Sin distinguir mayúsculas, tildes ni espacios repetidos, dentro del mismo nivel
    private function asegurarNombrePeriodoLibre(AnioLectivo $anio, string $nombre, int $nivel, ?int $excepto = null): void
    {
        $periodos = $anio->periodos()->where('nivel', $nivel)->when($excepto, fn ($q) => $q->where('id', '<>', $excepto));
        if (NombreUnico::ocupado($periodos, 'nombre', $nombre)) {
            throw ValidationException::withMessages(['nombre' => "El año lectivo {$anio->anio} ya tiene un periodo de nivel {$nivel} llamado «{$nombre}»."]);
        }
    }

    private function anioDelPeriodo(PeriodoEscolar $periodo): AnioLectivo
    {
        if ($periodo->anio_lectivo_id === null || $periodo->nivel === null) {
            throw new \DomainException('Este periodo es anterior a M2.2 y no tiene año lectivo ni nivel. Revíselo con el diagnóstico antes de usarlo.');
        }

        return AnioLectivo::lockForUpdate()->findOrFail($periodo->anio_lectivo_id);
    }

    private function cambiarEstado($modelo, string $estado): void
    {
        $modelo->forceFill(['estado' => $estado])->save();
    }
}
