<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Padre extends Model
{
    protected $table = 'padres';

    protected $casts = [
        'user_id' => 'integer',
    ];

    protected $fillable = [
        'nombre',
        'dui',
        'telefono',
        'email',
        'user_id',
    ];

    public function estudiantes()
    {
        return $this->belongsToMany(Estudiante::class, 'parientes', 'padre_id', 'estudiante_id')
            ->withPivot('parentesco');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
