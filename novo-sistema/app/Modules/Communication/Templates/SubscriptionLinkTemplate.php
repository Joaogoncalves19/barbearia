<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * Link de pagamento da assinatura (P10-04): TRANSACIONAL (pedido de um
 * servico pelo cliente, nunca campanha). O link e lido NA HORA do envio:
 * assinatura que ja ativou, link vencido ou substituido por outro = nao sai.
 * O link nao fica no registro (so a assinatura e a sessao).
 */
final class SubscriptionLinkTemplate extends BaseTemplate
{
    public function key(): string
    {
        return 'subscription_payment_link';
    }

    public function label(): string
    {
        return 'Link de pagamento da assinatura';
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $s = Subscription::query()->with(['planVersion.services', 'plan', 'customer'])->find((int) $m->param('subscription_id'));
        if ($s === null || $s->status !== SubscriptionStatus::Pending || $s->checkout_url === null) {
            return 'A assinatura não está mais aguardando pagamento.';
        }
        if ($s->checkout_session_id !== (string) $m->param('session')) {
            return 'Este link foi substituído por outro.';
        }
        if ($s->checkout_expires_at !== null && $s->checkout_expires_at->isPast()) {
            return 'O link venceu.';
        }

        return $this->message('Seu link para assinar o '.$s->planName(), 'Falta só o pagamento, '.$this->firstName($s->customer->name ?? null).'!', [
            'Para ativar a sua assinatura, conclua o pagamento pelo link seguro do Stripe.',
            'A assinatura ativa assim que o pagamento for confirmado.',
        ], [
            'Plano' => $s->planName(),
            'Valor' => $s->planVersion?->priceLabel() ?? '—',
            'Incluído' => $s->planVersion?->services->pluck('name')->join(', ') ?: '—',
            'Link válido até' => $s->checkout_expires_at !== null ? BusinessTime::formatLocal($s->checkout_expires_at, 'd/m/Y H:i') : '—',
        ], ['label' => 'Pagar e ativar', 'url' => $s->checkout_url], ['Não pediu esta assinatura? Ignore este e-mail: nada é cobrado sem o seu pagamento.']);
    }

    public function preview(): RenderedEmail
    {
        return $this->message('Seu link para assinar o Clube do Corte (exemplo)', 'Falta só o pagamento, Maria!', ['Exemplo com dados fictícios.'],
            ['Plano' => 'Clube do Corte (exemplo)', 'Valor' => 'R$ 99,00/mês', 'Incluído' => 'Corte', 'Link válido até' => '06/10/2026 08:00'],
            ['label' => 'Pagar e ativar', 'url' => url('/minha-conta')]);
    }
}
