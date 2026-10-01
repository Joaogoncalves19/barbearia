<?php

namespace App\Modules\Scheduling\Exceptions;

use RuntimeException;

/**
 * Regra de agendamento que nao e de disponibilidade: estado, prazo de
 * cancelamento/remarcacao, limite de remarcacoes, cadastro incompleto.
 */
final class BookingRuleViolation extends RuntimeException
{
    public const MESSAGES = [
        'invalid_status' => 'Este agendamento não pode mais ser alterado.',
        'cancel_deadline' => 'O prazo para cancelar por aqui já passou. Fale com a barbearia.',
        'reschedule_deadline' => 'O prazo para remarcar por aqui já passou. Fale com a barbearia.',
        'reschedule_limit' => 'Este agendamento já foi remarcado o máximo de vezes permitido. Fale com a barbearia.',
        'no_change' => 'Escolha um horário diferente do atual.',
        'customer_conflict' => 'Você já tem outro agendamento neste horário.',
        'customer_incomplete' => 'Complete seu cadastro (CPF) antes de agendar.',
        'contact_required' => 'Informe o cliente do agendamento.',
        'not_started' => 'Só é possível marcar falta depois do horário do agendamento.',
        'in_attendance' => 'O cliente já está em atendimento. Altere ou cancele pelo atendimento.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'Operação não permitida.');
    }
}
