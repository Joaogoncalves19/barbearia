<?php

namespace App\Modules\Communication\Mail;

use App\Modules\Communication\Templates\RenderedEmail;
use App\Modules\Receipts\Services\Receipts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * O UNICO Mailable dos e-mails do dominio (emails.md): recebe o e-mail ja
 * montado pelo modelo e o entrega com o layout da identidade visual. NAO
 * implementa ShouldQueue: quem esta na fila e o job SendEmailMessage, que
 * controla tentativas, falhas e unicidade. Marketing leva o cabecalho
 * List-Unsubscribe (descadastro de um clique nos leitores de e-mail).
 */
class CommunicationMail extends Mailable
{
    public function __construct(
        public readonly RenderedEmail $email,
        public readonly string $messageId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->email->subject);
    }

    public function headers(): Headers
    {
        $extra = ['X-Barbearia-Message' => $this->messageId];
        if ($this->email->unsubscribeUrl !== null) {
            $extra['List-Unsubscribe'] = '<'.$this->email->unsubscribeUrl.'>';
            $extra['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $extra);
    }

    public function content(): Content
    {
        return new Content(view: $this->email->view, with: [
            ...$this->email->data,
            'subjectLine' => $this->email->subject,
            'business' => app(Receipts::class)->business(),
            'unsubscribeUrl' => $this->email->unsubscribeUrl,
        ]);
    }
}
