<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Customers\Models\Customer;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Confirmacao de e-mail do cliente. Link assinado e com validade; leva o
 * public_id (nunca o id sequencial) e o hash do e-mail atual, entao deixa de
 * valer se o e-mail mudar.
 */
class CustomerVerifyEmail extends AccountNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof Customer) {
            throw new InvalidArgumentException('Confirmacao de e-mail e so para clientes.');
        }

        $minutos = (int) config('barbearia.security.email_verification_minutes');

        $url = URL::temporarySignedRoute('customer.verification.verify', now()->addMinutes($minutos), [
            'customer' => $notifiable->public_id,
            'hash' => sha1((string) $notifiable->getEmailForVerification()),
        ]);

        return $this->message('Confirme seu e-mail')
            ->line('Falta só confirmar que este e-mail é seu para ativar a conta.')
            ->action('Confirmar e-mail', $url)
            ->line("O link vale por {$minutos} minutos.")
            ->line('Se você não criou uma conta, ignore este e-mail.');
    }
}
