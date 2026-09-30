<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class CustomerMagicLink extends AccountNotification
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly int $minutes,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('Seu link para entrar')
            ->line('Use o botão abaixo para entrar na sua conta sem digitar senha.')
            ->action('Entrar na minha conta', route('customer.magic.show', ['token' => $this->token]))
            ->line("O link vale por {$this->minutes} minutos e só pode ser usado uma vez.")
            ->line($this->ignoreLine());
    }
}
