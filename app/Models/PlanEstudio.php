<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanEstudio extends Model
{
    protected $table = 'plan_estudios';

    protected $fillable = [
        'nombre',
        'especialidad_id',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'descripcion',
        'fecha_aprobacion',
    ];

    // estado = plan vigente (true) o no vigente (false)
    protected $casts = [
        'estado' => 'boolean',
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'fecha_aprobacion' => 'date:Y-m-d',
    ];

    public function especialidad()
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function asignaturas()
    {
        return $this->hasMany(Asignatura::class);
    }
}
