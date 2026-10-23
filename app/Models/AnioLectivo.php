<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Año lectivo (M2.2). Estados del diagrama ER: inactivo (aún no inicia),
 * activo y cierre. Las transiciones se hacen solo con
 * App\Services\CalendarioService, que valida las reglas.
 */
class AnioLectivo extends Model
{
    public const INACTIVO = 'inactivo';
    public const ACTIVO = 'activo';
    public const CIERRE = 'cierre';

    public const ESTADOS = [self::INACTIVO, self::ACTIVO, self::CIERRE];

    // Las fechas del año se comparan con el día de El Salvador (la aplicación guarda en UTC)
    public const ZONA_HORARIA = 'America/El_Salvador';

    protected $table = 'anios_lectivos';

    // anio: el año lectivo (2027), como la columna "anio" del diagrama
    protected $fillable = [
        'anio',
        'fecha_inicio',
        'fecha_fin',
        'inicio_matricula',
        'fin_matricula',
    ];

    protected $casts = [
        'anio' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'inicio_matricula' => 'date:Y-m-d',
        'fin_matricula' => 'date:Y-m-d',
    ];

    // Para el frontend: si el año ya comenzó y si hoy se pueden solicitar matrículas
    protected $appends = ['comenzado', 'matricula_abierta'];

    public function periodos()
    {
        return $this->hasMany(PeriodoEscolar::class)->orderBy('nivel')->orderBy('id');
    }

    public function secciones()
    {
        return $this->hasMany(Seccion::class);
    }

    public function matriculas()
    {
        return $this->hasMany(Matricula::class);
    }

    public function scopeActivo($query)
    {
        return $query->where('estado', self::ACTIVO);
    }

    public static function activo(): ?self
    {
        return static::where('estado', self::ACTIVO)->first();
    }

    public function fechasValidas(): bool
    {
        return $this->fecha_inicio !== null && $this->fecha_fin !== null && $this->fecha_inicio->lt($this->fecha_fin);
    }

    /** Fecha de hoy en El Salvador. */
    public static function hoy(): Carbon
    {
        return Carbon::now(self::ZONA_HORARIA)->startOfDay();
    }

    /** El año comenzó cuando llega su fecha de inicio (pedido de Josmy, 20/10/2026). */
    public function haComenzado(): bool
    {
        return $this->fecha_inicio !== null && self::hoy()->gte($this->fecha_inicio->toDateString());
    }

    /** Hoy está entre inicio_matricula y fin_matricula (ambos días incluidos). */
    public function matriculaAbierta(): bool
    {
        if ($this->inicio_matricula === null || $this->fin_matricula === null) {
            return false;
        }
        $hoy = self::hoy()->toDateString();

        return $hoy >= $this->inicio_matricula->toDateString() && $hoy <= $this->fin_matricula->toDateString();
    }

    /** Por qué no se pueden solicitar matrículas hoy (null si se puede). */
    public function motivoMatriculaCerrada(): ?string
    {
        if ($this->matriculaAbierta()) {
            return null;
        }
        if ($this->inicio_matricula === null || $this->fin_matricula === null) {
            return "El año lectivo {$this->anio} aún no tiene fechas de matrícula. La dirección debe indicarlas en el año lectivo.";
        }
        $periodo = $this->inicio_matricula->format('d/m/Y').' al '.$this->fin_matricula->format('d/m/Y');

        return self::hoy()->toDateString() < $this->inicio_matricula->toDateString()
            ? "La matrícula del año lectivo {$this->anio} aún no ha comenzado: se realiza del {$periodo}."
            : "La matrícula del año lectivo {$this->anio} ya finalizó: se realizó del {$periodo}.";
    }

    // null cuando la consulta no trajo las fechas (por ejemplo anioLectivo:id,anio)
    public function getComenzadoAttribute(): ?bool
    {
        return array_key_exists('fecha_inicio', $this->attributes) ? $this->haComenzado() : null;
    }

    public function getMatriculaAbiertaAttribute(): ?bool
    {
        return array_key_exists('inicio_matricula', $this->attributes) && array_key_exists('fin_matricula', $this->attributes) ? $this->matriculaAbierta() : null;
    }
}
