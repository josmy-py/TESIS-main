<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace para definir la contraseña. Se usa para la recuperación y para la
 * contraseña inicial de las cuentas que crea el director ($inicial = true).
 * Nunca incluye la contraseña.
 */
class RestablecerContrasena extends Notification
{
    public function __construct(public string $url, public int $minutos, public bool $inicial = false)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->inicial) {
            return (new MailMessage)
                ->subject('SIGA: define tu contraseña')
                ->greeting('Hola '.$notifiable->nombre)
                ->line('La dirección del centro escolar creó tu cuenta en SIGA.')
                ->action('Definir contraseña', $this->url)
                ->line("El enlace vence en {$this->minutos} minutos y solo puede usarse una vez. Si expira, pide a la dirección que lo reenvíe o usa \"Olvidé mi contraseña\".");
        }

        return (new MailMessage)
            ->subject('SIGA: restablecer contraseña')
            ->greeting('Hola '.$notifiable->nombre)
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $this->url)
            ->line("El enlace vence en {$this->minutos} minutos y solo puede usarse una vez.")
            ->line('Si no lo solicitaste, ignora este mensaje: tu contraseña no cambiará.');
    }
}
