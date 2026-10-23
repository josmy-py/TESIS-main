<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de Josmy (22/10/2026): sin la tabla intermedia plan_asignaturas. Un
 * plan de estudios tiene muchas asignaturas (plan_estudio_id) y cada
 * asignación apunta a su asignatura (asignaciones.asignatura_id).
 */
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
