<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Enums\CancelledBy;
use App\Modules\Scheduling\Enums\ItemType;
use App\Modules\Scheduling\Enums\PriceSource;
use App\Modules\Scheduling\Exceptions\BookingRuleViolation;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentEvent;
use App\Modules\Scheduling\Support\BookingPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Scheduling\Support\Channel;
use App\Modules\Scheduling\Support\Interval;
use App\Modules\Team\Models\Professional;
use App\Modules\Team\Services\ProfessionalDirectory;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * As UNICAS regras de criar, remarcar, cancelar, confirmar e marcar falta
 * (agendamento.md, regras-cancelamento.md, regras-reagendamento.md). Painel,
 * site e qualquer canal futuro chamam estes metodos; nenhum grava
 * agendamento por conta propria.
 *
 * Dupla reserva (item 12 do briefing): toda gravacao que ocupa horario
 * acontece numa transacao que PRIMEIRO escreve na linha do profissional
 * (schedule_version + 1) e SO DEPOIS revalida a disponibilidade e grava.
 * Duas reservas simultaneas para o mesmo profissional ficam em fila (bloqueio
 * de linha no MySQL; de escrita no SQLite, com transacao IMMEDIATE e
 * busy_timeout): a segunda so revalida depois que a primeira terminou, ve o
 * horario ocupado e recebe SlotUnavailable('conflict'). Nada fica gravado
 * pela metade (tudo ou nada).
 */
final class BookingService
{
    /**
     * Gancho SO para o teste de concorrencia: roda depois da revalidacao e
     * antes da gravacao, para alargar a janela de corrida.
     */
    public static ?Closure $afterCheck = null;

    public function __construct(
        private readonly Availability $availability,
        private readonly ProfessionalDirectory $directory,
    ) {}

    /**
     * Cria o agendamento. Sem profissional ("sem preferencia"): tenta os
     * profissionais que podem, na ordem da equipe, ate um aceitar.
     *
     * @throws SlotUnavailable|BookingRuleViolation
     */
    public function book(BookingRequest $r): Appointment
    {
        $this->assertContact($r);

        if ($r->professional !== null) {
            return $this->attempt($r, $r->professional);
        }

        $ultimo = null;
        foreach ($this->directory->bookableFor($r->service) as $candidato) {
            try {
                return $this->attempt($r, $candidato);
            } catch (SlotUnavailable $e) {
                $ultimo = $e;
            }
        }

        throw $ultimo ?? new SlotUnavailable(AvailabilityResult::unavailable(['service_unavailable']));
    }

    private function attempt(BookingRequest $r, Professional $professional): Appointment
    {
        return DB::transaction(function () use ($r, $professional): Appointment {
            $this->lockAgendaOf($professional->id);

            $pro = Professional::query()->findOrFail($professional->id);
            $servico = Service::query()->findOrFail($r->service->id);

            $resultado = $this->availability->check($servico, $pro, $r->start, $r->channel);
            if (! $resultado->isAvailable()) {
                throw new SlotUnavailable($resultado);
            }

            $janela = Interval::starting($r->start, $servico->duration_minutes);
            $this->assertCustomerFree($r->customer, $janela, null);

            if (self::$afterCheck !== null) {
                (self::$afterCheck)();
            }

            $pendente = $r->channel === Channel::Customer && BookingPolicy::current()->requiresConfirmation();

            $a = new Appointment([
                'customer_id' => $r->customer?->id,
                // Fotografias do momento (agendamento.md, "Snapshot").
                'customer_name' => $r->customer !== null ? $r->customer->name : (string) $r->contactName,
                'customer_email' => $r->customer?->email,
                'customer_phone' => $r->customer !== null ? $r->customer->phone : $r->contactPhone,
                'professional_id' => $pro->id,
                'professional_name' => $pro->display_name,
                'starts_at' => $janela->start,
                'ends_at' => $janela->end,
                'status' => $pendente ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
                'confirmed_at' => $pendente ? null : BusinessTime::now(),
                'source' => $r->source,
                'notes' => $r->notes,
                'created_by_user_id' => $r->actor instanceof User ? $r->actor->id : null,
            ]);
            $a->save();

            // Preco e duracao CONGELADOS no item: mudar o catalogo depois nao
            // altera este agendamento (precos.md).
            $a->items()->create([
                'item_type' => ItemType::Service,
                'service_id' => $servico->id,
                'name' => $servico->name,
                'quantity' => 1,
                'unit_price_cents' => $servico->price_cents,
                'total_cents' => $servico->price_cents,
                'duration_minutes' => $servico->duration_minutes,
                'price_source' => PriceSource::CatalogAtBooking,
            ]);

            app(AppointmentPricing::class)->refresh($a);

            $descricao = match (true) {
                $r->source === AppointmentSource::WalkIn => 'Encaixe: cliente chegou sem hora marcada.',
                $pendente => 'Agendamento criado (aguardando confirmação da barbearia).',
                default => 'Agendamento criado.',
            };
            $this->event($a, 'created', $descricao, $r->actor, [
                'canal' => $r->channel->value,
                'origem' => $r->source->value,
                'inicio' => BusinessTime::formatLocal($janela->start),
                'servico' => $servico->name,
                'preco_cents' => $servico->price_cents,
            ]);

            return $a;
        });
    }

    /**
     * Remarca (novo horario e/ou outro profissional). Mesmo servico, MESMO
     * preco e MESMA duracao fotografados; so muda quando e com quem.
     *
     * Com o cliente em atendimento a agenda nao se mexe por fora: so o
     * proprio atendimento (troca de profissional) remarca, passando
     * $byAttendance = true.
     *
     * @throws SlotUnavailable|BookingRuleViolation
     */
    public function reschedule(Appointment $appointment, CarbonImmutable $newStart, ?Professional $newProfessional, Channel $channel, User|Customer|null $actor, bool $byAttendance = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $newStart, $newProfessional, $channel, $actor, $byAttendance): Appointment {
            $a = Appointment::query()->with('items')->findOrFail($appointment->id);
            $destinoId = $newProfessional !== null ? $newProfessional->id : $a->professional_id;

            // Bloqueia as duas agendas envolvidas, sempre em ordem crescente de
            // id: duas remarcacoes cruzadas nunca travam uma a outra.
            $agendas = array_values(array_unique(array_map('intval', array_filter([$a->professional_id, $destinoId]))));
            sort($agendas);
            foreach ($agendas as $id) {
                $this->lockAgendaOf($id);
            }

            if (! $a->status->isOpen()) {
                throw new BookingRuleViolation('invalid_status');
            }
            if (! $byAttendance) {
                $this->assertNotInAttendance($a);
            }

            $politica = BookingPolicy::current();
            if ($channel === Channel::Customer) {
                if (BusinessTime::now()->addMinutes($politica->int('customer_reschedule_notice_minutes'))->gt(CarbonImmutable::instance($a->starts_at))) {
                    throw new BookingRuleViolation('reschedule_deadline');
                }
                if ($a->customer_reschedules >= $politica->int('customer_max_reschedules')) {
                    throw new BookingRuleViolation('reschedule_limit');
                }
            }

            $pro = Professional::query()->findOrFail($destinoId);
            if ($pro->id === $a->professional_id && CarbonImmutable::instance($a->starts_at)->eq($newStart)) {
                throw new BookingRuleViolation('no_change');
            }

            $item = $a->items->first(fn ($i) => $i->service_id !== null);
            $servico = Service::withTrashed()->findOrFail($item?->service_id);
            $duracao = (int) CarbonImmutable::instance($a->starts_at)->diffInMinutes(CarbonImmutable::instance($a->ends_at));

            $resultado = $this->availability->check($servico, $pro, $newStart, $channel, $a, $duracao);
            if (! $resultado->isAvailable()) {
                throw new SlotUnavailable($resultado);
            }

            $janela = Interval::starting($newStart, $duracao);
            if ($a->customer !== null) {
                $this->assertCustomerFree($a->customer, $janela, $a);
            }

            $antes = ['inicio' => BusinessTime::formatLocal($a->starts_at), 'profissional' => $a->professional_name];

            $a->forceFill(['starts_at' => $janela->start, 'ends_at' => $janela->end]);
            $a->professional_id = $pro->id;
            $a->professional_name = $pro->display_name;
            if ($channel === Channel::Customer) {
                $a->customer_reschedules++;
            }
            $a->save();

            $this->event($a, 'rescheduled', 'Agendamento remarcado.', $actor, [
                'de' => $antes['inicio'].' com '.$antes['profissional'],
                'para' => BusinessTime::formatLocal($janela->start).' com '.$pro->display_name,
                'canal' => $channel->value,
            ]);

            return $a;
        });
    }

    /**
     * Cancela (o registro fica; so muda o status). Cliente: so ate o prazo
     * da BookingPolicy. Com atendimento em vigor, cancela-se o atendimento
     * primeiro (o horario continua ocupado enquanto o cliente esta la).
     *
     * @throws BookingRuleViolation
     */
    public function cancel(Appointment $appointment, Channel $channel, User|Customer|null $actor, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $channel, $actor, $reason): Appointment {
            $a = Appointment::query()->findOrFail($appointment->id);
            if ($a->professional_id !== null) {
                $this->lockAgendaOf($a->professional_id);
            }

            if (! $a->status->canTransitionTo(AppointmentStatus::Cancelled)) {
                throw new BookingRuleViolation('invalid_status');
            }
            $this->assertNotInAttendance($a);
            if ($channel === Channel::Customer
                && BusinessTime::now()->addMinutes(BookingPolicy::current()->int('customer_cancel_notice_minutes'))->gt(CarbonImmutable::instance($a->starts_at))) {
                throw new BookingRuleViolation('cancel_deadline');
            }

            $motivo = $reason !== null ? mb_substr(trim($reason), 0, 64) : null;

            $a->status = AppointmentStatus::Cancelled;
            $a->forceFill(['cancelled_at' => BusinessTime::now()]);
            $a->cancelled_by = $channel === Channel::Customer ? CancelledBy::Customer : CancelledBy::Staff;
            $a->cancellation_reason = $motivo ?: null;
            $a->save();

            $this->event($a, 'cancelled', 'Agendamento cancelado.', $actor, ['por' => $a->cancelled_by->label(), 'motivo' => $motivo]);

            return $a;
        });
    }

    /** Pendente -> confirmado (quando a barbearia exige confirmacao, D-06). */
    public function confirm(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, AppointmentStatus::Confirmed, $actor, 'confirmed', 'Agendamento confirmado pela barbearia.', function (Appointment $a): void {
            $a->forceFill(['confirmed_at' => BusinessTime::now()]);
        });
    }

    /** Confirmado -> nao compareceu. So depois do horario de inicio. */
    public function markNoShow(Appointment $appointment, User $actor): Appointment
    {
        return $this->transition($appointment, AppointmentStatus::NoShow, $actor, 'no_show', 'Cliente não compareceu.', function (Appointment $a): void {
            if (CarbonImmutable::instance($a->starts_at)->gt(BusinessTime::now())) {
                throw new BookingRuleViolation('not_started');
            }
            $this->assertNotInAttendance($a);
        });
    }

    /**
     * Confirmado -> concluido. Chamado SO pela conclusao do atendimento
     * (AttendanceService::complete), dentro da mesma transacao: o
     * agendamento so e concluido se o atendimento foi.
     */
    public function complete(Appointment $appointment, User $actor, string $attendanceCode): Appointment
    {
        return $this->transition($appointment, AppointmentStatus::Completed, $actor, 'completed', 'Atendimento '.$attendanceCode.' concluído.', function (Appointment $a): void {
            $a->forceFill(['completed_at' => BusinessTime::now()]);
        });
    }

    public function updateNotes(Appointment $appointment, ?string $notes, User $actor): Appointment
    {
        $appointment->notes = $notes;
        $appointment->save();

        return $appointment;
    }

    /**
     * @param  Closure(Appointment): void  $before
     */
    private function transition(Appointment $appointment, AppointmentStatus $to, User $actor, string $type, string $description, Closure $before): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $actor, $type, $description, $before): Appointment {
            $a = Appointment::query()->findOrFail($appointment->id);
            if (! $a->status->canTransitionTo($to)) {
                throw new BookingRuleViolation('invalid_status');
            }
            $before($a);
            $a->status = $to;
            $a->save();
            $this->event($a, $type, $description, $actor);

            return $a;
        });
    }

    /**
     * Protecao contra dupla reserva: escreve na linha do profissional. Precisa
     * ser a PRIMEIRA escrita da transacao.
     */
    private function lockAgendaOf(int $professionalId): void
    {
        DB::table('professionals')->where('id', $professionalId)->increment('schedule_version');
    }

    private function assertContact(BookingRequest $r): void
    {
        if ($r->customer === null && trim((string) $r->contactName) === '') {
            throw new BookingRuleViolation('contact_required');
        }
        if ($r->customer !== null && $r->channel === Channel::Customer && ($r->customer->needsProfileCompletion() || ! $r->customer->canSignIn())) {
            throw new BookingRuleViolation('customer_incomplete');
        }
    }

    /**
     * O cliente chegou (atendimento em vigor): o agendamento nao e cancelado,
     * remarcado nem marcado como falta por fora do atendimento, senao o
     * horario ficaria livre na agenda com o cliente na cadeira.
     */
    private function assertNotInAttendance(Appointment $a): void
    {
        if ($a->attendance()->exists()) {
            throw new BookingRuleViolation('in_attendance');
        }
    }

    /** O mesmo cliente nao fica em dois lugares ao mesmo tempo. */
    private function assertCustomerFree(?Customer $customer, Interval $slot, ?Appointment $ignore): void
    {
        if ($customer === null) {
            return;
        }

        $ocupado = Appointment::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, AppointmentStatus::blockingSlot()))
            ->where('starts_at', '<', $slot->end)->where('ends_at', '>', $slot->start)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->exists();

        if ($ocupado) {
            throw new BookingRuleViolation('customer_conflict');
        }
    }

    /**
     * @param  array<string, scalar|null>  $data
     */
    private function event(Appointment $a, string $type, string $description, User|Customer|null $actor, array $data = []): void
    {
        AppointmentEvent::query()->create([
            'appointment_id' => $a->id,
            'type' => $type,
            'description' => $description,
            'actor_label' => match (true) {
                $actor instanceof User => $actor->name.' (equipe)',
                $actor instanceof Customer => $actor->name.' (cliente)',
                default => 'Sistema',
            },
            'data' => $data ?: null,
            'occurred_at' => BusinessTime::now(),
        ]);
    }
}
