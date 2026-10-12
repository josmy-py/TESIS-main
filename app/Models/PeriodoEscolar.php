<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoEscolar extends Model
{
    public const PENDIENTE = 'pendiente';
    public const ABIERTO = 'abierto';
    public const CERRADO = 'cerrado';

    protected $table = 'periodo_escolares';

    protected $fillable = [
        'nombre',
        'nivel',
        'anio_lectivo_id',
        'numero',
        'fecha_inicio',
        'fecha_fin',
    ];

    protected $hidden = ['abierto_en'];

    protected $casts = [
        'numero' => 'integer',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
    ];

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

    public function fechasValidas(): bool
    {
        return $this->fecha_inicio !== null && $this->fecha_fin !== null && $this->fecha_inicio->lt($this->fecha_fin);
    }

    // Boletas, evaluaciones o conductas registradas en el periodo
    public function tieneRegistrosAcademicos(): bool
    {
        return $this->boletas()->exists() || $this->evaluaciones()->exists() || $this->conductas()->exists();
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): solo se registran o modifican
     * datos en un periodo abierto de un año activo.
     */
    public function permiteRegistros(): bool
    {
        return $this->estado === self::ABIERTO
            && $this->anioLectivo !== null
            && $this->anioLectivo->estado === AnioLectivo::ACTIVO;
    }
}
