<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pariente extends Model
{
    protected $table = 'parientes';

    protected $fillable = [
        'padre_id',
        'estudiante_id',
        'parentesco',

    ];

    public function padre()
    {
        return $this->belongsTo(Padre::class, 'padre_id');
    }

    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class, 'estudiante_id');
    }
}
