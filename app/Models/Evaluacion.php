<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Evaluacion extends Model
{
    protected $table = 'evaluaciones';

    protected $fillable = [
        'nombre',
        'porcentaje',
        'periodo_escolar_id',
        'asignacion_id',
    ];

    public function asignacion()
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoEscolar::class, 'periodo_escolar_id');
    }

    public function calificaciones()
    {
        return $this->hasMany(Calificacion::class);
    }
}
