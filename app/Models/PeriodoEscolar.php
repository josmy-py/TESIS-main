<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoEscolar extends Model
{
    protected $table = 'periodo_escolares';

    protected $fillable = [
        'nombre',
        'nivel',
        'anio_lectivo_id',
    ];

    // nivel: número del periodo dentro del año lectivo (1, 2, 3...)
    protected $casts = [
        'nivel' => 'integer',
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

    // Boletas, evaluaciones o conductas registradas en el periodo
    public function tieneRegistrosAcademicos(): bool
    {
        return $this->boletas()->exists() || $this->evaluaciones()->exists() || $this->conductas()->exists();
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): solo se registran o modifican
     * datos en un periodo de un año activo.
     */
    public function permiteRegistros(): bool
    {
        return $this->anioLectivo !== null
            && $this->anioLectivo->estado === AnioLectivo::ACTIVO;
    }
}
