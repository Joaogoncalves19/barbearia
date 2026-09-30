<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class CustomerResetPassword extends AccountNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.customers.expire');

        return $this->message('Redefinir sua senha')
            ->line('Recebemos um pedido para redefinir a senha da sua conta.')
            ->action('Criar nova senha', route('customer.password.reset', ['token' => $this->token]))
            ->line("O link vale por {$minutos} minutos e só pode ser usado uma vez.")
            ->line($this->ignoreLine());
    }
}
