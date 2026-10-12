<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotaFinal extends Model
{
    protected $table = 'notas_anuales';

    protected $fillable = [
        'matricula_id',
        'promedio_anual',
        'estado',
    ];

    public function matricula()
    {
        return $this->belongsTo(Matricula::class, 'matricula_id');
    }
}
