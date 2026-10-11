<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Profesor extends Model
{
    protected $table = 'profesores';

    protected $fillable = [
        'nombre',
        'fecha_nacimiento',
        'codigo',
        'telefono',
        'dui',
        'direccion',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    public function secciones()
    {
        return $this->hasMany(Seccion::class);
    }
}
