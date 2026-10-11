<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Especialidad extends Model
{
    protected $table = 'especialidades';

    protected $fillable = [
        'nombre',
    ];

    public function matricula()
    {
        return $this->hasMany(Matricula::class);
    }

    public function planesEstudios()
    {
        return $this->hasMany(PlanEstudio::class);
    }
}
