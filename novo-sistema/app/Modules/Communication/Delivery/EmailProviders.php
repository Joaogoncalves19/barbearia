<?php

namespace App\Modules\Communication\Delivery;

use InvalidArgumentException;

/**
 * Escolhe o provedor configurado (EMAIL_PROVIDER). Lido a cada envio: trocar
 * a configuracao nao exige reiniciar nada alem do worker.
 */
final class EmailProviders
{
    /** @var array<string, class-string<EmailProvider>> */
    public const AVAILABLE = [
        'mailer' => MailerProvider::class,
        'resend' => ResendProvider::class,
    ];

    public function current(): EmailProvider
    {
        $nome = (string) config('barbearia.email_provider', 'mailer');
        $classe = self::AVAILABLE[$nome] ?? throw new InvalidArgumentException("Provedor de e-mail desconhecido: {$nome}");

        return app($classe);
    }
}
