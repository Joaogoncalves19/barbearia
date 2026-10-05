<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;

/**
 * Agendamento confirmado, remarcado ou cancelado (lembretes.md §1). Montado
 * na hora: confirmacao de agendamento que ja foi cancelado nao sai;
 * remarcacao que ja foi substituida por outra nao sai (a mais nova sai).
 */
final class BookingTemplate extends BaseTemplate
{
    public function __construct(private readonly string $kind) {}

    public function key(): string
    {
        return 'booking_'.$this->kind;
    }

    public function label(): string
    {
        return match ($this->kind) {
            'confirmed' => 'Agendamento confirmado',
            'rescheduled' => 'Agendamento remarcado',
            default => 'Agendamento cancelado',
        };
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $a = Appointment::query()->find((int) $m->param('appointment_id'));
        if ($a === null) {
            return 'Agendamento não existe mais.';
        }
        $ativo = in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true);
        $nome = $this->firstName($a->customer_name);
        $link = $a->customer_id !== null ? ['label' => 'Ver meu agendamento', 'url' => route('account.appointments.show', $a)] : null;

        return match ($this->kind) {
            'confirmed' => ! $ativo ? 'Agendamento não está mais ativo.'
                : $this->message('Agendamento confirmado: '.$a->code, 'Tudo certo, '.$nome.'!', [
                    $a->status === AppointmentStatus::Pending ? 'Recebemos o seu agendamento. Ele aguarda a confirmação da barbearia.' : 'Seu horário está reservado.',
                ], $this->appointmentDetails($a), $link, ['Precisa mudar? Remarque ou cancele pela sua conta, dentro do prazo.']),
            'rescheduled' => ! $ativo || $a->starts_at === null || $a->starts_at->getTimestamp() !== (int) $m->param('starts_at') ? 'Agendamento mudou de novo ou não está mais ativo.'
                : $this->message('Agendamento remarcado: '.$a->code, 'Seu horário mudou, '.$nome, ['Confira o novo horário:'], $this->appointmentDetails($a), $link),
            default => $a->status !== AppointmentStatus::Cancelled ? 'Agendamento não está cancelado.'
                : $this->message('Agendamento cancelado: '.$a->code, 'Agendamento cancelado', [
                    'O horário abaixo foi cancelado'.($a->cancellation_reason ? ' ('.$a->cancellation_reason.')' : '').'.',
                    'Quer marcar outro? É só agendar de novo.',
                ], $this->appointmentDetails($a), ['label' => 'Agendar', 'url' => route('booking.services')]),
        };
    }

    public function preview(): RenderedEmail
    {
        return $this->message($this->label().': AG-EXEMPLO', match ($this->kind) {
            'confirmed' => 'Tudo certo, Maria!', 'rescheduled' => 'Seu horário mudou, Maria', default => 'Agendamento cancelado',
        }, ['Exemplo com dados fictícios.'], $this->fakeDetails(), ['label' => 'Ver meu agendamento', 'url' => url('/minha-conta')]);
    }
}
