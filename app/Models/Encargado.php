<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Encargado extends Model
{
    protected $table = 'encargados';

    protected $casts = [
        'user_id' => 'integer',
    ];

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
