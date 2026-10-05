<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * E-mails de assinatura (D-50): ativacao, falha de pagamento, cancelamento
 * agendado e cancelamento efetivo. Enfileirados so DEPOIS da consolidacao do
 * estado (SubscriptionLifecycle) e conferidos de novo NA HORA DO ENVIO
 * contra o estado consolidado atual: se a cobranca ja foi recuperada, o
 * aviso de falha nao sai; se o cancelamento foi desfeito, o aviso nao sai.
 */
final class SubscriptionTemplate extends BaseTemplate
{
    public function __construct(private readonly string $kind) {}

    public function key(): string
    {
        return 'subscription_'.$this->kind;
    }

    public function label(): string
    {
        return match ($this->kind) {
            'activated' => 'Assinatura ativada',
            'payment_failed' => 'Pagamento da assinatura recusado',
            'cancel_scheduled' => 'Cancelamento da assinatura agendado',
            default => 'Assinatura cancelada',
        };
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $s = Subscription::query()->with(['planVersion.services', 'plan'])->find((int) $m->param('subscription_id'));
        if ($s === null) {
            return 'Assinatura não existe mais.';
        }
        $esperado = match ($this->kind) {
            'activated' => [SubscriptionStatus::Active, SubscriptionStatus::CancelScheduled, SubscriptionStatus::PastDue],
            'payment_failed' => [SubscriptionStatus::PastDue],
            'cancel_scheduled' => [SubscriptionStatus::CancelScheduled],
            default => [SubscriptionStatus::Cancelled],
        };
        if (! in_array($s->status, $esperado, true)) {
            return 'A assinatura mudou de situação ('.($s->status?->label() ?? '—').'): aviso não se aplica mais.';
        }
        $nome = $this->firstName($s->customer->name ?? null);
        $ate = $s->ends_on?->format('d/m/Y') ?? '—';
        $detalhes = ['Plano' => $s->planName(), 'Incluído' => $s->planVersion?->services->pluck('name')->join(', ') ?: '—', 'Benefício até' => $ate];
        $conta = ['label' => 'Ver minha assinatura', 'url' => route('account.subscription')];

        return match ($this->kind) {
            'activated' => $this->message('Sua assinatura está ativa', 'Bem-vindo ao '.$s->planName().', '.$nome.'!', [
                'O pagamento foi confirmado. Os serviços incluídos saem de graça enquanto a assinatura estiver em dia.',
            ], $detalhes, $conta),
            'payment_failed' => $this->message('Não conseguimos cobrar a sua assinatura', 'Pagamento recusado', [
                'A cobrança da renovação não foi aprovada. O Stripe vai tentar de novo nos próximos dias; se precisar, atualize o cartão pelo e-mail do Stripe.',
                'Seus benefícios valem até '.$ate.'.',
            ], $detalhes, $conta),
            'cancel_scheduled' => $this->message('Cancelamento da assinatura agendado', 'Renovação cancelada', [
                'Não haverá nova cobrança. Seus benefícios continuam até '.$ate.'. Mudou de ideia? Dá para manter a assinatura pela sua conta até lá.',
            ], $detalhes, $conta),
            default => $this->message('Sua assinatura foi encerrada', 'Assinatura encerrada', [
                'Sua assinatura foi cancelada e não haverá novas cobranças.',
            ], $detalhes),
        };
    }

    public function preview(): RenderedEmail
    {
        return $this->message($this->label(), $this->label(), ['Exemplo com dados fictícios.'],
            ['Plano' => 'Clube do Corte (exemplo)', 'Incluído' => 'Corte', 'Benefício até' => '05/11/2026'], ['label' => 'Ver minha assinatura', 'url' => url('/minha-conta')]);
    }
}
