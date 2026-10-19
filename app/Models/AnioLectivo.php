<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    protected $table = 'anios_lectivos';

    // anio: el año lectivo (2027), como la columna "anio" del diagrama
    protected $fillable = [
        'anio',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $casts = [
        'anio' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
    ];

    public function periodos()
    {
        return $this->hasMany(PeriodoEscolar::class)->orderBy('nivel');
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
}
