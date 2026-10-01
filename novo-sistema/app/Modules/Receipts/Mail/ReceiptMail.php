<?php

namespace App\Modules\Receipts\Mail;

use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Services\Receipts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Comprovante por e-mail, em fila: o MESMO conteudo da versao impressa (a
 * mesma view e os mesmos dados, montados pelo Receipts). A fila guarda so o
 * tipo e o id; os documentos sao historico (atendimento concluido, repasse,
 * caixa fechado nao mudam), entao o e-mail sai igual ao da tela.
 */
class ReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ReceiptType $type,
        public readonly int $receiptId,
        public readonly string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $dados = app(Receipts::class)->data($this->type, $this->receiptId);

        return new Content(view: 'mail.receipt', with: ['partial' => $this->type->view(), 'title' => $this->subjectLine, 'forEmail' => true, ...$dados]);
    }
}
