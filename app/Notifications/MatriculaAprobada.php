<?php

namespace App\Notifications;

use App\Models\Matricula;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * M6.1 (D-M6, tesis pág. 64): aviso «Matrícula Aprobada» para la cuenta del
 * estudiante. Se guarda en la tabla estándar `notifications`.
 */
class MatriculaAprobada extends Notification
{
    use Queueable;

    public function __construct(private Matricula $matricula) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->matricula->loadMissing(['estudiante:id,nombre', 'anioLectivo:id,nombre', 'seccion.grado:id,nombre']);

        return [
            'tipo' => 'matricula_aprobada',
            'titulo' => 'Matrícula Aprobada',
            'mensaje' => "La matrícula de {$m->estudiante->nombre} para el año {$m->anioLectivo?->nombre} fue aprobada: {$m->seccion?->grado?->nombre} sección {$m->seccion?->nombre}.",
            'matricula_id' => $m->id,
            'estudiante_id' => $m->estudiante_id,
        ];
    }
}
