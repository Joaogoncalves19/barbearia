<?php

namespace App\Modules\Communication\Delivery;

use App\Modules\Communication\Mail\CommunicationMail;
use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Communication\Templates\RenderedEmail;
use Illuminate\Support\Facades\Mail;

/**
 * Entrega pelo mailer do Laravel (MAIL_MAILER: log em desenvolvimento,
 * array nos testes, smtp se um dia for preciso). Padrao enquanto
 * EMAIL_PROVIDER nao apontar para um provedor por API.
 */
final class MailerProvider implements EmailProvider
{
    public function name(): string
    {
        return 'mailer';
    }

    public function send(EmailMessage $record, RenderedEmail $email): ?string
    {
        Mail::to($record->to_email, $record->to_name)->send(new CommunicationMail($email, $record->public_id));

        return null;
    }
}
