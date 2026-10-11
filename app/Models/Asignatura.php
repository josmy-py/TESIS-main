<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asignatura extends Model
{
    protected $table = 'asignaturas';

    protected $fillable = [
        'nombre',
        'plan_estudio_id',
    ];

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    public function planEstudio()
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_estudio_id');
    }
}
