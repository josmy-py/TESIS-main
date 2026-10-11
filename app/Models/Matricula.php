<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matricula extends Model
{
    protected $table = 'matriculas';

    protected $fillable = [
        'ingreso',
        'anio',
        'estado',
        'foto',
        'documento',
        'estudiante_id',
        'seccion_id',
        'es_trasladado',
        'especialidad_id',
        'enfermedad_id',
        'encargado_id',
        'user_id',
    ];

    // Relación Mucho a Muchos con Discapacidad (vía tabla 'expedientes')
    public function discapacidades()
    {
        return $this->belongsToMany(
            Discapacidad::class,
            'expedientes',
            'matricula_id',
            'discapacidad_id'
        )->withTimestamps();
    }

    // Relaciones directas (Pertenece a...)
    public function estudiante()
    {
        return $this->belongsTo(Estudiante::class, 'estudiante_id');
    }

    public function seccion()
    {
        return $this->belongsTo(Seccion::class, 'seccion_id');
    }

    public function especialidad()
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function encargado()
    {
        return $this->belongsTo(Encargado::class, 'encargado_id');
    }

    public function enfermedad()
    {
        return $this->belongsTo(Enfermedad::class, 'enfermedad_id');
    }

    public function notaFinal()
    {
        return $this->hasMany(NotaFinal::class);
    }

    public function boletas()
    {
        return $this->hasMany(Boleta::class);
    }

    public function conductas()
    {
        return $this->hasMany(Conducta::class);
    }

    public function calificaciones()
    {
        return $this->hasMany(Calificacion::class);
    }
}
