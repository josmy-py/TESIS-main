<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaCalendario extends Model
{
    protected $table = 'auditoria_calendario';

    protected $fillable = [
        'entidad',
        'entidad_id',
        'accion',
        'estado_anterior',
        'estado_nuevo',
        'motivo',
        'user_id',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
