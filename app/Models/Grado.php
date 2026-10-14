<?php

namespace App\Models;

use App\Support\CatalogoGrados;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Grado extends Model
{
    protected $table = 'grados';
    protected $fillable = [
        'nombre',
    ];

    // El orden académico no se guarda (no está en el diagrama): es el del
    // nombre oficial en el catálogo cerrado de grados
    protected $appends = ['orden'];

    public function secciones()
    {
        return $this->hasMany(Seccion::class);
    }

    public function getOrdenAttribute(): ?int
    {
        return CatalogoGrados::orden($this->nombre);
    }

    // Orden académico; un grado fuera del catálogo (solo datos anteriores) va al final
    public static function ordenAcademico(): Collection
    {
        return static::orderBy('nombre')->get()
            ->sortBy(fn (Grado $g) => [$g->orden === null ? 1 : 0, $g->orden ?? 0])
            ->values();
    }

    // Grado que sigue en la progresión académica (el siguiente orden registrado)
    public function siguiente(): ?Grado
    {
        if ($this->orden === null) {
            return null;
        }

        return static::ordenAcademico()
            ->first(fn (Grado $g) => $g->orden !== null && $g->orden > $this->orden);
    }
}
