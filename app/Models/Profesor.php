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
        'especialidad',
        'fecha_contratacion',
        'user_id',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date:Y-m-d',
        'fecha_contratacion' => 'date:Y-m-d',
    ];

    public const PREFIJO_CODIGO = 'PROF-';

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
