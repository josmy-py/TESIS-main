<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Boleta extends Model
{
    protected $table = 'boletas';

    protected $fillable = [
        'promedio',
        'matricula_id',
        'asignacion_id',
        'periodo_escolar_id',

    ];

    public function matricula()
    {
        return $this->belongsTo(Matricula::class, 'matricula_id');
    }

    public function asignacion()
    {
        return $this->belongsTo(Asignacion::class, 'asignacion_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoEscolar::class, 'periodo_escolar_id');
    }
}
