<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Año lectivo (M2.2). Las transiciones de estado se hacen solo con
 * App\Services\CalendarioService, que valida las reglas.
 */
class AnioLectivo extends Model
{
    public const PLANIFICADO = 'planificado';
    public const ACTIVO = 'activo';
    public const CERRADO = 'cerrado';

    public const ESTADOS = [self::PLANIFICADO, self::ACTIVO, self::CERRADO];

    protected $table = 'anios_lectivos';

    protected $fillable = [
        'nombre',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $hidden = ['activo_unico'];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
    ];

    public function periodos()
    {
        return $this->hasMany(PeriodoEscolar::class)->orderBy('numero');
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

    /**
     * Año calendario que se guarda en los campos antiguos "anio" durante la
     * transición: el de la fecha de inicio o, si no hay fechas, el nombre
     * cuando es un año de 4 cifras.
     */
    public function anioCalendario(): ?string
    {
        if ($this->fecha_inicio !== null) {
            return $this->fecha_inicio->format('Y');
        }

        return preg_match('/^\d{4}$/', $this->nombre) ? $this->nombre : null;
    }
}
