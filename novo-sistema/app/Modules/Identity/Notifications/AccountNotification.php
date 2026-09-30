<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base dos e-mails de conta (equipe e clientes).
 *
 * - Enfileirados: o tempo de resposta do formulario nao revela se a conta
 *   existe (enumeracao) e uma falha de SMTP nao derruba a tela.
 * - Criptografados na fila: o link leva um token que nao pode ficar em
 *   texto puro na tabela jobs/failed_jobs.
 */
abstract class AccountNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    protected function message(string $subject): MailMessage
    {
        return (new MailMessage)
            ->subject($subject.' · '.config('app.name'))
            ->greeting('Olá!')
            ->salutation('Até breve, '.config('app.name').'.');
    }

    protected function ignoreLine(): string
    {
        return 'Se não foi você quem pediu, ignore este e-mail: nada muda na sua conta.';
    }
}
