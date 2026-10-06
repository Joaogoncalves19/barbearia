<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Vai para o endereco NOVO: confirma que a pessoa controla esse e-mail. */
class CustomerEmailChangeLink extends AccountNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('barbearia.security.email_change_minutes', 60);

        return $this->message('Confirme seu novo e-mail')
            ->line('Você pediu para usar este endereço na sua conta.')
            ->action('Confirmar novo e-mail', route('account.email.confirm.show', ['token' => $this->token]))
            ->line("O link vale por {$minutos} minutos e só pode ser usado uma vez. É preciso estar conectado na conta.")
            ->line('Se não foi você quem pediu, ignore este e-mail: nada muda.');
    }
}
