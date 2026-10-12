<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Grado extends Model
{
    protected $table = 'grados';
    protected $fillable = [
        'nombre',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function secciones()
    {
        return $this->hasMany(Seccion::class);
    }

    // Planes de estudio del grado (M2.3); sus asignaturas forman el pensum
    public function planesEstudio()
    {
        return $this->hasMany(PlanEstudio::class);
    }

    // Orden académico; los grados heredados sin orden van al final
    public function scopeOrdenAcademico(Builder $query): Builder
    {
        return $query->orderByRaw('orden IS NULL')->orderBy('orden')->orderBy('nombre');
    }

    // Grado que sigue en la progresión académica (el siguiente orden registrado)
    public function siguiente(): ?Grado
    {
        if ($this->orden === null) {
            return null;
        }

        return static::where('orden', '>', $this->orden)->orderBy('orden')->first();
    }

}
