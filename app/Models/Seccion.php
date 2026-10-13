<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seccion extends Model
{
    protected $table = 'secciones';

    public const TURNOS = ['matutino', 'vespertino'];

    // D-S3: límite provisional; el máximo real está pendiente de confirmar con la institución
    public const CAPACIDAD_MINIMA = 1;
    public const CAPACIDAD_MAXIMA = 50;

    // Matrículas que ocupan un cupo (las rechazadas lo liberan)
    public const ESTADOS_OCUPAN_CUPO = ['pendiente', 'aprobado'];

    protected $fillable = [
        'nombre',
        'capacidad',
        'turno',
        'anio_lectivo_id',
        'grado_id',
        'profesor_id',
    ];

    protected $casts = [
        'capacidad' => 'integer',
        'grado_id' => 'integer',
        'profesor_id' => 'integer',
        'anio_lectivo_id' => 'integer',
    ];

    // "  a " y "A" son la misma sección: sin espacios sobrantes y en mayúsculas
    public static function normalizarNombre(?string $nombre): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) $nombre)), 'UTF-8');
    }

    public function grado()
    {
        return $this->belongsTo(Grado::class, 'grado_id');
    }

    public function profesor()
    {
        return $this->belongsTo(Profesor::class, 'profesor_id');
    }

    public function matriculas()
    {
        return $this->hasMany(Matricula::class);
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    public function anioLectivo()
    {
        return $this->belongsTo(AnioLectivo::class, 'anio_lectivo_id');
    }
}
