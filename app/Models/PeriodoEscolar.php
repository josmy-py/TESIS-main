<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoEscolar extends Model
{
    protected $table = 'periodo_escolares';

    // estado (pedido de Josmy, 19/10/2026): pendiente al crearse; el director lo
    // activa (diagrama: activo) y lo cierra al terminar el trimestre o periodo
    public const PENDIENTE = 'pendiente';
    public const ACTIVO = 'activo';
    public const CERRADO = 'cerrado';
    public const ESTADOS = [self::PENDIENTE, self::ACTIVO, self::CERRADO];

    // nivel (pedido de Josmy, 23/10/2026; tesis: "formato de nivel escolar"):
    // nivel académico al que pertenece el periodo, no su número
    public const NIVEL_BASICA = 1;
    public const NIVEL_BACHILLERATO = 2;
    public const NIVELES = [
        self::NIVEL_BASICA => 'Parvularia y Educación Básica',
        self::NIVEL_BACHILLERATO => 'Bachillerato',
    ];

    // Columnas del diagrama: nombre y nivel (más el año lectivo al que
    // pertenece y el estado). El estado solo cambia con CalendarioService
    protected $fillable = [
        'nombre',
        'nivel',
        'anio_lectivo_id',
    ];

    protected $appends = ['nivel_nombre'];

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    // nivel: 1 = Parvularia y Educación Básica, 2 = Bachillerato
    protected $casts = [
        'nivel' => 'integer',
    ];

    public function getNivelNombreAttribute(): ?string
    {
        return self::NIVELES[$this->nivel] ?? null;
    }

    /** Nivel académico de un grado según su orden del catálogo (Parvularia y Básica hasta noveno grado). */
    public static function nivelDeGrado(?int $orden): ?int
    {
        return $orden === null ? null : ($orden <= 9 ? self::NIVEL_BASICA : self::NIVEL_BACHILLERATO);
    }

    /** Nivel académico de una sección (por su grado). */
    public static function nivelDeSeccion(?Seccion $seccion): ?int
    {
        return self::nivelDeGrado($seccion?->grado?->orden);
    }

    /** El periodo es del año lectivo de la sección y de su nivel académico. */
    public function esDeLaSeccion(?Seccion $seccion): bool
    {
        $nivel = self::nivelDeSeccion($seccion);

        return $seccion !== null
            && (int) $this->anio_lectivo_id === (int) $seccion->anio_lectivo_id
            && ($nivel === null || (int) $this->nivel === $nivel);
    }

    /** Periodos de un año lectivo para una sección: solo los de su nivel académico. */
    public static function deLaSeccion(?Seccion $seccion)
    {
        return static::where('anio_lectivo_id', $seccion?->anio_lectivo_id ?? 0)
            ->when(self::nivelDeSeccion($seccion), fn ($q, $nivel) => $q->where('nivel', $nivel))
            ->orderBy('nivel')->orderBy('id');
    }

    public function anioLectivo()
    {
        return $this->belongsTo(AnioLectivo::class, 'anio_lectivo_id');
    }

    public function boletas()
    {
        return $this->hasMany(Boleta::class);
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class);
    }

    public function conductas()
    {
        return $this->hasMany(Conducta::class);
    }

    // Boletas, evaluaciones o conductas registradas en el periodo
    public function tieneRegistrosAcademicos(): bool
    {
        return $this->boletas()->exists() || $this->evaluaciones()->exists() || $this->conductas()->exists();
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): solo se registran o modifican
     * datos en un periodo no cerrado de un año activo.
     */
    public function permiteRegistros(): bool
    {
        return $this->estado !== self::CERRADO
            && $this->anioLectivo !== null
            && $this->anioLectivo->estado === AnioLectivo::ACTIVO;
    }

    /** Mensaje para cuando permiteRegistros() es falso; $accion: "no se pueden registrar notas". */
    public function motivoBloqueo(string $accion): string
    {
        return $this->estado === self::CERRADO
            ? "El periodo {$this->nombre} está cerrado: {$accion}."
            : "El año lectivo de este periodo no está activo: {$accion}.";
    }
}
