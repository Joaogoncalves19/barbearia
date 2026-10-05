<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Receipts\Enums\ReceiptType;
use App\Modules\Receipts\Services\Receipts;

/** Comprovante por e-mail (Fase 8), agora pela fila central. */
final class ReceiptTemplate extends BaseTemplate
{
    public function key(): string
    {
        return 'receipt';
    }

    public function label(): string
    {
        return 'Comprovante';
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $tipo = ReceiptType::tryFrom((string) $m->param('type'));
        if ($tipo === null) {
            return 'Tipo de comprovante desconhecido.';
        }
        $dados = app(Receipts::class)->data($tipo, (int) $m->param('id'));

        return new RenderedEmail((string) $m->param('subject'), 'mail.communication.receipt', ['partial' => $tipo->view(), 'forEmail' => true, ...$dados]);
    }

    public function preview(): RenderedEmail
    {
        return $this->message('Comprovante (exemplo)', 'Comprovante', ['O comprovante usa a mesma parte da impressão (atendimento, repasse, vale-presente ou caixa).']);
    }
}
