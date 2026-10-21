<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Diagrama ER (21/10/2026): asignatura que forma parte de un plan de estudios.
 * Una misma asignatura puede estar en varios planes; la asignación académica
 * apunta a esta fila (plan_asignatura_id).
 */
class PlanAsignatura extends Model
{
    protected $table = 'plan_asignaturas';

    protected $fillable = [
        'plan_estudio_id',
        'asignatura_id',
    ];

    protected $casts = [
        'plan_estudio_id' => 'integer',
        'asignatura_id' => 'integer',
    ];

    public function planEstudio()
    {
        return $this->belongsTo(PlanEstudio::class, 'plan_estudio_id');
    }

    public function asignatura()
    {
        return $this->belongsTo(Asignatura::class, 'asignatura_id');
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacion::class);
    }

    /** Enlaza al plan la asignatura con ese nombre; la crea en el catálogo si no existe. */
    public static function enlazar(int $planEstudioId, string $nombre): self
    {
        $asignatura = Asignatura::firstOrCreate(['nombre' => $nombre]);

        return self::firstOrCreate(['plan_estudio_id' => $planEstudioId, 'asignatura_id' => $asignatura->id]);
    }
}
