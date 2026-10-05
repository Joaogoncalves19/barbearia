<?php

namespace App\Modules\Subscriptions\Enums;

/**
 * Estado da assinatura (assinaturas.md §3). O estado diz em que ponto da
 * cobranca a assinatura esta; o DIREITO ao beneficio e outra coisa (data
 * paga, `ends_on`): ver SubscriptionBenefits.
 *
 * Transicoes permitidas (canTransitionTo); nenhuma requisicao HTTP muda o
 * estado direto: so os servicos (acao da equipe ou do cliente, evento do
 * Stripe, rotina de expiracao).
 */
enum SubscriptionStatus: string
{
    /** Pagamento da adesao ainda nao confirmado (link/checkout aberto). Sem beneficio. */
    case Pending = 'pending';

    /** Paga e renovando. */
    case Active = 'active';

    /** Cobranca da renovacao falhou; o Stripe esta tentando de novo. */
    case PastDue = 'past_due';

    /** Nao renova mais; beneficio ate o fim do periodo pago. */
    case CancelScheduled = 'cancel_scheduled';

    /** Encerrada por cancelamento (imediato, no fim do periodo ou por inadimplencia). */
    case Cancelled = 'cancelled';

    /** Encerrada sem renovacao (manual vencida) ou adesao nunca paga. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento em atraso',
            self::CancelScheduled => 'Cancelamento agendado',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }

    /** Ocupa a vaga de "uma assinatura vigente por cliente" (sentinela active_customer_id). */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Pending, self::Active, self::PastDue, self::CancelScheduled], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }

    /** Entra no MRR: renova no proximo ciclo (cancelamento agendado nao entra). */
    public function isRecurring(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }

    public function canTransitionTo(self $to): bool
    {
        if ($to === $this) {
            return true;
        }

        return in_array($to, match ($this) {
            self::Pending => [self::Active, self::PastDue, self::CancelScheduled, self::Cancelled, self::Expired],
            self::Active => [self::PastDue, self::CancelScheduled, self::Cancelled, self::Expired],
            self::PastDue => [self::Active, self::CancelScheduled, self::Cancelled, self::Expired],
            self::CancelScheduled => [self::Active, self::PastDue, self::Cancelled, self::Expired],
            self::Cancelled, self::Expired => [],
        }, true);
    }

    /** @return list<string> */
    public static function currentValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isCurrent())));
    }
}
