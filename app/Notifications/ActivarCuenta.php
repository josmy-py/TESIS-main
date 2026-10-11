<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActivarCuenta extends Notification
{
    public function __construct(public string $url, public int $minutos)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SIGA: activa tu cuenta')
            ->greeting('Hola '.$notifiable->nombre)
            ->line('Recibimos tu registro como encargado en SIGA.')
            ->action('Activar cuenta', $this->url)
            ->line("El enlace vence en {$this->minutos} minutos. Si expira, puedes pedir uno nuevo desde la pantalla de inicio de sesión.")
            ->line('Si no solicitaste esta cuenta, ignora este mensaje.');
    }
}
