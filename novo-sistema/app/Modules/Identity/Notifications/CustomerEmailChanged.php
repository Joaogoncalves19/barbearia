<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Vai para o endereco ANTIGO depois da troca: se nao foi a pessoa, ela sabe
 * na hora. Nao mostra o endereco novo (quem tomou a conta nao ganha nada
 * com isso, e o aviso nao vaza o dado).
 */
class CustomerEmailChanged extends AccountNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->message('O e-mail da sua conta foi alterado')
            ->line('O e-mail de acesso da sua conta acabou de ser trocado por outro endereço.')
            ->line('Se foi você, não precisa fazer nada.')
            ->line('Se não foi você, fale com a barbearia o quanto antes para recuperarmos o acesso.');
    }
}
