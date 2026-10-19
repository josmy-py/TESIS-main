<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoEscolar extends Model
{
    protected $table = 'periodo_escolares';

    // estado (pedido de Josmy, 19/10/2026): pendiente al crearse; el director lo
    // pone en curso y lo cierra al terminar el trimestre o periodo
    public const PENDIENTE = 'pendiente';
    public const EN_CURSO = 'en_curso';
    public const CERRADO = 'cerrado';
    public const ESTADOS = [self::PENDIENTE, self::EN_CURSO, self::CERRADO];

    // Columnas del diagrama: nombre_periodo y nivel (más el año lectivo al que
    // pertenece y el estado). El estado solo cambia con CalendarioService
    protected $fillable = [
        'nombre_periodo',
        'nivel',
        'anio_lectivo_id',
    ];

    protected $attributes = [
        'estado' => self::PENDIENTE,
    ];

    // nivel: número del periodo dentro del año lectivo (1, 2, 3...)
    protected $casts = [
        'nivel' => 'integer',
    ];

    public function anioLectivo()
    {
        return $this->belongsTo(AnioLectivo::class, 'anio_lectivo_id');
    }

    public function boletas()
    {
        return $this->hasMany(Boleta::class);
    }

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class);
    }

    public function conductas()
    {
        return $this->hasMany(Conducta::class);
    }

    // Boletas, evaluaciones o conductas registradas en el periodo
    public function tieneRegistrosAcademicos(): bool
    {
        return $this->boletas()->exists() || $this->evaluaciones()->exists() || $this->conductas()->exists();
    }

    /**
     * Regla para M7 (notas) y M8 (conducta): solo se registran o modifican
     * datos en un periodo no cerrado de un año activo.
     */
    public function permiteRegistros(): bool
    {
        return $this->estado !== self::CERRADO
            && $this->anioLectivo !== null
            && $this->anioLectivo->estado === AnioLectivo::ACTIVO;
    }

    /** Mensaje para cuando permiteRegistros() es falso; $accion: "no se pueden registrar notas". */
    public function motivoBloqueo(string $accion): string
    {
        return $this->estado === self::CERRADO
            ? "El periodo {$this->nombre_periodo} está cerrado: {$accion}."
            : "El año lectivo de este periodo no está activo: {$accion}.";
    }
}
