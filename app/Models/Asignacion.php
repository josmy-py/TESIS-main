<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * M4: un docente imparte una materia en una sección; el año lectivo es el de
 * la sección. Una materia de una sección tiene un solo docente (índice único).
 * Diagrama ER (21/10/2026): la materia es una asignatura de un plan
 * (plan_asignatura_id).
 */
class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected $fillable = [
        'profesor_id',
        'plan_asignatura_id',
        'seccion_id',
    ];

    protected $casts = [
        'profesor_id' => 'integer',
        'plan_asignatura_id' => 'integer',
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

    public function planAsignatura()
    {
        return $this->belongsTo(PlanAsignatura::class, 'plan_asignatura_id');
    }

    // Asignatura de la materia, a través de su plan_asignatura
    public function asignatura()
    {
        return $this->hasOneThrough(Asignatura::class, PlanAsignatura::class, 'id', 'id', 'plan_asignatura_id', 'asignatura_id');
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

