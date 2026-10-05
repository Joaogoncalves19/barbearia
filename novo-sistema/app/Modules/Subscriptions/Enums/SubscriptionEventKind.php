<?php

namespace App\Modules\Subscriptions\Enums;

/** Tipos do historico da assinatura (subscription_events, so inclusao). */
enum SubscriptionEventKind: string
{
    case Created = 'created';
    case CheckoutStarted = 'checkout_started';
    case Activated = 'activated';
    case Renewed = 'renewed';
    case PaymentFailed = 'payment_failed';
    case PaymentPending = 'payment_pending';
    case Recovered = 'recovered';
    case StatusSynced = 'status_synced';
    case CancelScheduled = 'cancel_scheduled';
    case Reactivated = 'reactivated';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case BenefitApplied = 'benefit_applied';
    case LinkEmailed = 'link_emailed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Assinatura criada',
            self::CheckoutStarted => 'Pagamento iniciado',
            self::Activated => 'Ativada',
            self::Renewed => 'Renovada',
            self::PaymentFailed => 'Pagamento recusado',
            self::PaymentPending => 'Pagamento pendente',
            self::Recovered => 'Cobrança recuperada',
            self::StatusSynced => 'Situação atualizada pelo Stripe',
            self::CancelScheduled => 'Cancelamento agendado',
            self::Reactivated => 'Reativada',
            self::Cancelled => 'Cancelada',
            self::Expired => 'Expirada',
            self::Refunded => 'Reembolso',
            self::BenefitApplied => 'Benefício aplicado ao agendamento',
            self::LinkEmailed => 'Link de pagamento enviado por e-mail',
        };
    }
}
