<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class StaffResetPassword extends AccountNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.users.expire');

        return $this->message('Redefinir a senha do painel')
            ->line('Recebemos um pedido para redefinir a senha do seu acesso ao painel da equipe.')
            ->action('Criar nova senha', route('staff.password.reset', ['token' => $this->token]))
            ->line("O link vale por {$minutos} minutos e só pode ser usado uma vez.")
            ->line($this->ignoreLine());
    }
}
