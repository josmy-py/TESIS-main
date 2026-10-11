<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conducta extends Model
{
    protected $table = 'conductas';

    protected $fillable = [
        'descripcion',
        'valoracion',
        'matricula_id',
        'periodo_escolar_id',
    ];

    public function matricula()
    {
        return $this->belongsTo(Matricula::class, 'matricula_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoEscolar::class, 'periodo_escolar_id');
    }
}
