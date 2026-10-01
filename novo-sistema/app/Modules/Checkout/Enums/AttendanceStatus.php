<?php

namespace App\Modules\Checkout\Enums;

/**
 * Ciclo de vida do atendimento (atendimento.md, "Estados").
 *
 *   open ──> in_progress ──> completed
 *     │           │
 *     └───────────┴──> cancelled
 *
 * open: cliente chegou (atendimento aberto). in_progress: na cadeira.
 * completed: servico prestado, valores congelados e pagamento registrado.
 * cancelled: desistencia antes da conclusao (nada foi cobrado nem baixado).
 * completed e cancelled sao finais: correcao de um concluido e feita por
 * estorno (pagamento) ou reversao (estoque), nunca voltando o status.
 * "Aguardando pagamento" nao existe: so se conclui pago (decisao do dono).
 */
enum AttendanceStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::InProgress => 'Em atendimento',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Itens, consumo, desconto e profissional ainda podem mudar. */
    public function isEditable(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
