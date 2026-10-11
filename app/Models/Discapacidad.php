<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discapacidad extends Model
{
    protected $table = 'discapacidades';

    protected $fillable = [
        'nombre',
    ];

    public function matriculas()
    {
        return $this->belongsToMany(Matricula::class,
         'expedientes',
          'discapacidad_id',
           'matricula_id');
    }

}
