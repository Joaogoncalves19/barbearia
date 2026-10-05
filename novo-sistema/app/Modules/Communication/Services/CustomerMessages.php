<?php

namespace App\Modules\Communication\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * O que o DOMINIO comunica ao cliente (emails.md §2): um metodo por
 * acontecimento, com a chave de unicidade de cada um. Chamado DENTRO da
 * transacao do acontecimento (o registro so existe se o acontecimento
 * existir; o envio sai depois do commit). Endereco: o e-mail da conta do
 * cliente (ou o informado no agendamento). Encaixe e agendamento antigo nao
 * geram e-mail de confirmacao.
 */
final class CustomerMessages
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly InAppNotifier $inApp,
    ) {}

    public function bookingConfirmed(Appointment $a): void
    {
        if (in_array($a->source, [AppointmentSource::WalkIn, AppointmentSource::Legacy], true)) {
            return;
        }
        $this->appointmentEmail($a, 'booking_confirmed', 'booking_confirmed:'.$a->id, []);
    }

    /** Agendamento que aguardava a barbearia foi confirmado (D-06). */
    public function bookingApproved(Appointment $a): void
    {
        if (in_array($a->source, [AppointmentSource::WalkIn, AppointmentSource::Legacy], true)) {
            return;
        }
        $this->appointmentEmail($a, 'booking_confirmed', 'booking_confirmed:'.$a->id.':approved', []);
    }

    public function bookingRescheduled(Appointment $a): void
    {
        $ts = $a->starts_at?->getTimestamp();
        $this->appointmentEmail($a, 'booking_rescheduled', 'booking_rescheduled:'.$a->id.':'.$ts, ['starts_at' => $ts]);
    }

    public function bookingCancelled(Appointment $a): void
    {
        if ($a->source === AppointmentSource::WalkIn) {
            return;
        }
        $this->appointmentEmail($a, 'booking_cancelled', 'booking_cancelled:'.$a->id, []);
    }

    /** Lembrete (e-mail + aviso na conta); a chave e do HORARIO lembrado. */
    public function reminder(Appointment $a, string $kind, int $scheduledFor): ?int
    {
        $msg = $this->appointmentEmail($a, 'reminder_'.$kind, 'reminder:'.$kind.':'.$a->id.':'.$scheduledFor, ['scheduled_for' => $scheduledFor], respectReminderPreference: true);
        if ($a->customer_id !== null) {
            $hora = $a->starts_at !== null ? BusinessTime::formatLocal($a->starts_at, 'H:i') : '';
            $this->inApp->notify($a->customer_id, 'reminder', ($kind === 'day_before' ? 'Lembrete: seu horário é amanhã às ' : 'Seu horário é hoje às ').$hora.' com '.($a->professional_name ?? 'a barbearia').'.',
                route('account.appointments.show', $a), 'reminder:'.$kind.':'.$a->id.':'.$scheduledFor);
        }

        return $msg;
    }

    public function reviewRequest(Attendance $at): void
    {
        $cliente = $at->customer_id !== null ? Customer::query()->find($at->customer_id) : null;
        if ($cliente === null) {
            return;
        }
        if ($cliente->email !== null) {
            $this->outbox->queue('review_request', $cliente->email, $cliente->name, $cliente, ['attendance_id' => $at->id], 'review_request:'.$at->id, 'attendance', $at->id);
        }
        $this->inApp->notify($cliente->id, 'review_request', 'Como foi o seu atendimento '.$at->code.'? Conte para a gente.', route('account.reviews.create', $at), 'review_request:'.$at->id);
    }

    /**
     * Assinatura (D-50): so depois da consolidacao do estado. Renovacao
     * normal so gera aviso na conta.
     */
    public function subscription(Subscription $s, string $kind, string $eventKey): void
    {
        $cliente = Customer::query()->find($s->customer_id);
        if ($cliente === null) {
            return;
        }
        $avisos = [
            'activated' => 'Sua assinatura '.$s->planName().' está ativa.',
            'payment_failed' => 'Não conseguimos cobrar a renovação da sua assinatura. Seus benefícios valem até '.($s->ends_on?->format('d/m/Y') ?? 'a data paga').'.',
            'cancel_scheduled' => 'Renovação da assinatura cancelada. Benefícios até '.($s->ends_on?->format('d/m/Y') ?? 'o fim do período').'.',
            'cancelled' => 'Sua assinatura foi encerrada.',
            'renewed' => 'Sua assinatura foi renovada: benefícios até '.($s->ends_on?->format('d/m/Y') ?? '').'.',
        ];
        $chave = 'subscription:'.$s->id.':'.$kind.':'.$eventKey;
        $this->inApp->notify($cliente->id, 'subscription', $avisos[$kind] ?? 'Sua assinatura mudou.', route('account.subscription'), $chave);
        if ($kind !== 'renewed' && $cliente->email !== null) {
            $this->outbox->queue('subscription_'.$kind, $cliente->email, $cliente->name, $cliente, ['subscription_id' => $s->id], $chave, 'subscription', $s->id);
        }
    }

    /**
     * @param  array<string, scalar|null>  $extra
     */
    private function appointmentEmail(Appointment $a, string $template, string $key, array $extra, bool $respectReminderPreference = false): ?int
    {
        $cliente = $a->customer_id !== null ? Customer::query()->find($a->customer_id) : null;
        if ($respectReminderPreference && $cliente !== null && ! $cliente->email_reminders_enabled) {
            return null;
        }
        $email = $cliente->email ?? $a->customer_email;
        if ($email === null || $email === '') {
            return null;
        }

        return $this->outbox->queue($template, $email, $a->customer_name ?? $cliente?->name, $cliente, ['appointment_id' => $a->id, ...$extra], $key, 'appointment', $a->id)?->id;
    }
}
