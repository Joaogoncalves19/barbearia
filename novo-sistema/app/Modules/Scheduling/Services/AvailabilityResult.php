<?php

namespace App\Modules\Scheduling\Services;

/**
 * Resposta da regra de disponibilidade: livre, ou os motivos (codigos) de
 * nao estar. Os textos para as telas saem daqui, num lugar so.
 */
final class AvailabilityResult
{
    public const MESSAGES = [
        'service_unavailable' => 'Este serviço não está disponível para agendamento.',
        'professional_unavailable' => 'Este profissional não está recebendo agendamentos.',
        'professional_not_qualified' => 'Este profissional não executa este serviço.',
        'invalid_time' => 'Horário inválido.',
        'past' => 'Este horário já passou.',
        'too_soon' => 'Este horário está muito próximo. Escolha um horário com mais antecedência.',
        'too_far' => 'Ainda não é possível agendar tão à frente.',
        'outside_hours' => 'Fora do horário de atendimento.',
        'time_off' => 'O profissional está de folga neste dia.',
        'break' => 'Horário de pausa do profissional.',
        'blocked' => 'Este horário está bloqueado na agenda.',
        'conflict' => 'Este horário acabou de ser ocupado. Escolha outro.',
    ];

    /**
     * @param  list<string>  $reasons
     */
    private function __construct(public readonly array $reasons) {}

    public static function available(): self
    {
        return new self([]);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function unavailable(array $reasons): self
    {
        return new self($reasons);
    }

    public function isAvailable(): bool
    {
        return $this->reasons === [];
    }

    public function has(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /** Mensagem do primeiro motivo (o mais importante). */
    public function message(): string
    {
        return self::MESSAGES[$this->reasons[0] ?? ''] ?? 'Horário indisponível.';
    }
}
