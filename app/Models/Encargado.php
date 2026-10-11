<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Encargado extends Model
{
    protected $table = 'encargados';

    protected $fillable = [
        'nombre',
        'parentesco',
        'telefono',
        'dui',
        'direccion',
        'user_id',
    ];

    public function matriculas()
    {
        return $this->hasMany(Matricula::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
