<?php

namespace App\Modules\Communication\Services;

use App\Modules\Communication\Support\CommunicationSettings;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentReminder;
use App\Modules\Scheduling\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Lembretes de agendamento (lembretes.md; D-51, como no sistema antigo):
 * - vespera: agendamentos CONFIRMADOS de amanha, a partir da hora
 *   configurada (padrao 9h);
 * - algumas horas antes: confirmados que comecam na janela [agora, agora + H].
 *
 * Um lembrete e do HORARIO: unico por (agendamento, tipo, horario) no banco.
 * O agendador rodando duas vezes (ou dois servidores) nao duplica: o segundo
 * bate no indice unico e pula. Remarcar abre um lembrete novo para o novo
 * horario; o e-mail do horario antigo, se ainda estiver na fila, e
 * descartado na hora do envio (ReminderTemplate). Cancelado nao e lembrado.
 */
final class Reminders
{
    public function __construct(private readonly CustomerMessages $messages) {}

    /**
     * @return array{day_before: int, hours_before: int, ja_lembrados: int}
     */
    public function run(): array
    {
        $cfg = CommunicationSettings::current();
        $agora = BusinessTime::now();
        $n = ['day_before' => 0, 'hours_before' => 0, 'ja_lembrados' => 0];

        if ($cfg->bool('reminder_hours_before_enabled')) {
            $limite = $agora->addHours($cfg->int('reminder_hours_before'));
            foreach ($this->confirmedBetween($agora, $limite) as $a) {
                $this->send($a, 'hours_before') ? $n['hours_before']++ : $n['ja_lembrados']++;
            }
        }
        if ($cfg->bool('reminder_day_before_enabled') && (int) BusinessTime::local($agora)->format('G') >= $cfg->int('reminder_day_before_hour')) {
            $amanha = BusinessTime::dayBounds(CarbonImmutable::parse(BusinessTime::today())->addDay()->toDateString());
            foreach ($this->confirmedBetween($amanha->start, $amanha->end) as $a) {
                $this->send($a, 'day_before') ? $n['day_before']++ : $n['ja_lembrados']++;
            }
        }

        return $n;
    }

    /**
     * @return iterable<Appointment>
     */
    private function confirmedBetween(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        return Appointment::query()->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->orderBy('starts_at')->lazyById(200);
    }

    /** Envia (enfileira) o lembrete do horario atual do agendamento; false se ja lembrado. */
    public function send(Appointment $a, string $kind): bool
    {
        $horario = $a->starts_at;
        if ($horario === null) {
            return false;
        }
        try {
            return DB::transaction(function () use ($a, $kind, $horario): bool {
                $r = AppointmentReminder::query()->create([
                    'appointment_id' => $a->id, 'kind' => $kind, 'scheduled_for' => $horario, 'status' => 'sent', 'sent_at' => BusinessTime::now(),
                ]);
                $email = $this->messages->reminder($a, $kind, $horario->getTimestamp());
                $r->forceFill(['email_message_id' => $email, 'notified_in_app' => $a->customer_id !== null])->save();

                return true;
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                return false; // outro processo ja lembrou este horario
            }
            throw $e;
        }
    }
}
