<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enfermedad extends Model
{
    protected $table = 'enfermedades';

    protected $fillable = [
        'nombre',
        'medicamento',
    ];

    public function matriculas()
    {
        return $this->hasMany(Matricula::class);
    }
}
