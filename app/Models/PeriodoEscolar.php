<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoEscolar extends Model
{
    protected $table = 'periodo_escolares';

    protected $fillable = [
        'nombre',
        'nivel',
    ];

    public function boletas()
    {
        return $this->hasMany(Boleta::class);
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class);
    }

    public function conductas()
    {
        return $this->hasMany(Conducta::class);
    }
}
