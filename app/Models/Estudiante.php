<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estudiante extends Model
{
    protected $table = 'estudiantes';

    protected $casts = [
        'user_id' => 'integer',
        'distrito_id' => 'integer',
    ];

    protected $fillable = [
        'nombre',
        'genero',
        'fecha_nacimiento',
        'distrito_id',
        'nie',
        'direccion',
        'canton',
        'estado',
        'user_id',
    ];

    public function distrito()
    {
        return $this->belongsTo(Distrito::class, 'distrito_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function padres()
    {
        return $this->belongsToMany(Padre::class,
         'parientes',
          'estudiante_id',
           'padre_id')->withPivot('parentesco');
    }

    public function getDepartamento()
    {
        return $this->distrito?->municipio?->departamento;
    }

    public function matriculas()
    {
        return $this->hasMany(Matricula::class);
    }

    public function ultimaMatricula()
    {
        return $this->hasOne(Matricula::class)->latestOfMany();
    }
}
