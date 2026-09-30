<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Ciclo de vida do agendamento.
 *
 *   pending ─┬─> confirmed ─┬─> completed
 *            │              ├─> no_show ──> completed (correcao da equipe)
 *            │              └─> cancelled
 *            ├─> awaiting_payment ─┬─> confirmed
 *            │                     └─> cancelled
 *            └─> cancelled
 *
 * completed e cancelled sao finais: correcao de um atendimento concluido e
 * feita por estorno/ajuste, nunca voltando o status (historico imutavel).
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::AwaitingPayment => 'Aguardando pagamento',
            self::Confirmed => 'Confirmado',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
            self::NoShow => 'Não compareceu',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::NoShow, self::Cancelled],
            self::NoShow => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Ocupa o horario na agenda (conta para conflito). */
    public function blocksSlot(): bool
    {
        return in_array($this, [self::Pending, self::AwaitingPayment, self::Confirmed, self::Completed], true);
    }

    /**
     * Status que ocupam horario (para consultas no banco).
     *
     * @return list<self>
     */
    public static function blockingSlot(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->blocksSlot()));
    }

    /** Ainda pode ser remarcado ou cancelado (nao aconteceu nem terminou). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::AwaitingPayment, self::Confirmed], true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
