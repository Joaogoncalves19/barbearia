<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Cadastro recusado por conflito com outro cadastro (CPF ou telefone ja
 * usados). A tela nao diz o motivo (enumeracao); este e-mail vai so para o
 * endereco informado e tambem nao diz qual dado conflitou.
 */
class CustomerRegistrationNotCompleted extends AccountNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('Não conseguimos concluir seu cadastro')
            ->line('Recebemos um pedido de cadastro com este e-mail, mas alguns dados informados já pertencem a outro cadastro.')
            ->line('Se você já é cliente, entre com o e-mail que usou antes ou fale com a barbearia para regularizar.')
            ->line('Se não foi você, ignore este e-mail.');
    }
}
