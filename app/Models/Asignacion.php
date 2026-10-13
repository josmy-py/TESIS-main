<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * M4: un docente imparte una materia en una sección; el año lectivo es el de
 * la sección. Una materia de una sección tiene un solo docente (índice único).
 */
class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected $fillable = [
        'profesor_id',
        'asignatura_id',
        'seccion_id',
    ];

    protected $casts = [
        'profesor_id' => 'integer',
        'asignatura_id' => 'integer',
        'seccion_id' => 'integer',
    ];

    public function profesor()
    {
        return $this->belongsTo(Profesor::class, 'profesor_id');
    }

    public function seccion()
    {
        return $this->belongsTo(Seccion::class, 'seccion_id');
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class, 'asignatura_id');
    }

    public function boletas()
    {
        return $this->hasMany(Boleta::class);
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class);
    }
}

