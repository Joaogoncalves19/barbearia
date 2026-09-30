<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Alguem tentou criar conta com um e-mail que ja tem cadastro. A tela mostra
 * a mesma mensagem de um cadastro novo (sem enumeracao); o dono do e-mail
 * recebe aqui o caminho para entrar ou definir a senha.
 */
class CustomerAccountAlreadyExists extends AccountNotification
{
    public function __construct(#[\SensitiveParameter] public readonly string $resetToken) {}

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = (int) config('auth.passwords.customers.expire');

        return $this->message('Você já tem cadastro')
            ->line('Alguém pediu para criar uma conta com este e-mail, mas ele já está cadastrado.')
            ->line('Se foi você, entre normalmente ou defina uma nova senha pelo botão abaixo.')
            ->action('Definir minha senha', route('customer.password.reset', ['token' => $this->resetToken]))
            ->line("O link vale por {$minutos} minutos e só pode ser usado uma vez.")
            ->line($this->ignoreLine());
    }
}
