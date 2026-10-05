<?php

namespace App\Modules\Communication\Templates;

use App\Modules\Communication\Models\EmailMessage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use Illuminate\Support\Facades\URL;

/**
 * Lembrete de agendamento (D-51, como no sistema antigo): vespera e algumas
 * horas antes, com link de confirmacao de presenca. Conferido NA HORA DO
 * ENVIO (lembretes.md §3): agendamento cancelado, remarcado (outro horario),
 * ja passado ou cliente que desligou os lembretes por e-mail = nao envia.
 */
final class ReminderTemplate extends BaseTemplate
{
    public function __construct(private readonly string $kind) {}

    public function key(): string
    {
        return 'reminder_'.$this->kind;
    }

    public function label(): string
    {
        return $this->kind === 'day_before' ? 'Lembrete da véspera' : 'Lembrete algumas horas antes';
    }

    public function render(EmailMessage $m): RenderedEmail|string
    {
        $a = Appointment::query()->find((int) $m->param('appointment_id'));
        if ($a === null || ! in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
            return 'Agendamento cancelado ou encerrado.';
        }
        if ($a->starts_at === null || $a->starts_at->getTimestamp() !== (int) $m->param('scheduled_for')) {
            return 'Agendamento remarcado: este lembrete era do horário antigo.';
        }
        if ($a->starts_at->isPast()) {
            return 'O horário já passou.';
        }
        $cliente = $a->customer_id !== null ? Customer::query()->find($a->customer_id) : null;
        if ($cliente !== null && ! $cliente->email_reminders_enabled) {
            return 'O cliente desligou os lembretes por e-mail.';
        }
        $hora = BusinessTime::formatLocal($a->starts_at, 'H:i');
        $presenca = $a->presence_confirmed_at === null
            ? ['label' => 'Confirmar presença', 'url' => URL::temporarySignedRoute('presence.show', $a->starts_at, ['appointment' => $a->code])]
            : null;

        return $this->message(
            $this->kind === 'day_before' ? 'Lembrete: seu horário é amanhã às '.$hora : 'Seu horário é hoje às '.$hora,
            ($this->kind === 'day_before' ? 'Até amanhã, ' : 'Até já, ').$this->firstName($a->customer_name).'!',
            [$a->presence_confirmed_at !== null ? 'Sua presença está confirmada.' : 'Confirme sua presença com um clique (não precisa entrar na conta).'],
            $this->appointmentDetails($a),
            $presenca,
            ['Não vai poder vir? Remarque ou cancele pela sua conta, dentro do prazo, para liberar o horário.'],
            null,
            $a->customer_id !== null ? ['text' => 'Não quer mais lembretes por e-mail?', 'label' => 'Ajustar preferências', 'url' => route('account.profile.edit')] : null,
        );
    }

    public function preview(): RenderedEmail
    {
        return $this->message($this->kind === 'day_before' ? 'Lembrete: seu horário é amanhã às 10:00' : 'Seu horário é hoje às 10:00',
            $this->kind === 'day_before' ? 'Até amanhã, Maria!' : 'Até já, Maria!', ['Confirme sua presença com um clique (não precisa entrar na conta).'],
            $this->fakeDetails(), ['label' => 'Confirmar presença', 'url' => url('/presenca/exemplo')]);
    }
}
