<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla expedientes del modelo ER: discapacidades de una matrícula
 * (Matricula.discapacidades() la usa como tabla intermedia).
 */
class Expediente extends Model
{
    protected $table = 'expedientes';

    protected $fillable = [
        'discapacidad_id',
        'matricula_id',
    ];

    public function matricula()
    {
        return $this->belongsTo(Matricula::class, 'matricula_id');
    }

    public function discapacidad()
    {
        return $this->belongsTo(Discapacidad::class, 'discapacidad_id');
    }
}
