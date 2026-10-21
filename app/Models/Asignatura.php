<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de asignaturas (diagrama ER 21/10/2026: solo nombre). Los planes de
 * estudio las incluyen mediante plan_asignaturas.
 */
class Asignatura extends Model
{
    protected $table = 'asignaturas';

    protected $fillable = [
        'nombre',
    ];

    public function planAsignaturas()
    {
        return $this->hasMany(PlanAsignatura::class);
    }

    public function planes()
    {
        return $this->belongsToMany(PlanEstudio::class, 'plan_asignaturas')->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasManyThrough(Asignacion::class, PlanAsignatura::class);
    }
}
