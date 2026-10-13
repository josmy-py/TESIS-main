<?php

namespace App\Notifications;

use App\Models\Matricula;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * M6.1 (D-M6, tesis pág. 64): aviso de matrícula rechazada con el motivo
 * obligatorio. El motivo solo se guarda aquí y en la bitácora (sin columna nueva).
 */
class MatriculaRechazada extends Notification
{
    use Queueable;

    public function __construct(private Matricula $matricula, private string $motivo) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->matricula->loadMissing(['estudiante:id,nombre', 'anioLectivo:id,nombre']);

        return [
            'tipo' => 'matricula_rechazada',
            'titulo' => 'Matrícula Rechazada',
            'mensaje' => "La matrícula de {$m->estudiante->nombre} para el año {$m->anioLectivo?->nombre} fue rechazada. Motivo: {$this->motivo}",
            'motivo' => $this->motivo,
            'matricula_id' => $m->id,
            'estudiante_id' => $m->estudiante_id,
        ];
    }
}
