<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\LoyaltyEntryKind;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Loyalty\Exceptions\PromotionRejected;
use App\Modules\Loyalty\Models\LoyaltyEntry;
use App\Modules\Loyalty\Models\LoyaltyRedemption;
use App\Modules\Loyalty\Support\PromotionPolicy;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Pontos de fidelidade como razao (fidelidade.md): o saldo e sempre a soma
 * dos lancamentos; nao existe saldo gravado que possa divergir.
 *
 * Disponivel = saldo - pontos reservados em resgates ainda nao concluidos
 * (decisao do dono: os pontos so saem na conclusao, mas nao podem ser
 * prometidos duas vezes).
 *
 * Trava: customers.loyalty_version (primeira escrita no cliente), em
 * reserva, ajuste e conclusao.
 */
class LoyaltyLedger
{
    public function balance(Customer|int $customer): int
    {
        $id = $customer instanceof Customer ? $customer->getKey() : $customer;

        return (int) LoyaltyEntry::where('customer_id', $id)->sum('points');
    }

    /** Pontos prometidos em resgates reservados (exceto os do agendamento informado). */
    public function reserved(Customer|int $customer, ?int $exceptAppointmentId = null): int
    {
        $id = $customer instanceof Customer ? $customer->getKey() : $customer;

        return (int) LoyaltyRedemption::query()->where('customer_id', $id)->where('status', RedemptionStatus::Reserved)
            ->when($exceptAppointmentId, fn ($q) => $q->where(fn ($x) => $x->whereNull('appointment_id')->orWhere('appointment_id', '<>', $exceptAppointmentId)))
            ->sum('points');
    }

    public function available(Customer|int $customer, ?int $exceptAppointmentId = null): int
    {
        return $this->balance($customer) - $this->reserved($customer, $exceptAppointmentId);
    }

    /** Primeira escrita da transacao no cliente: trava o saldo de pontos. */
    public function lock(int $customerId): void
    {
        DB::table('customers')->where('id', $customerId)->increment('loyalty_version');
    }

    public function credit(Customer $customer, int $points, LoyaltyEntryKind $kind, ?string $description = null, ?int $appointmentId = null): LoyaltyEntry
    {
        if ($points <= 0) {
            throw DomainRuleViolation::rule('R-PONTOS', 'Credito de pontos deve ser positivo.');
        }

        return $this->record($customer->getKey(), $points, $kind, $description, ['appointment_id' => $appointmentId]);
    }

    /** Resgate direto: nunca deixa o saldo negativo. */
    public function debit(Customer $customer, int $points, LoyaltyEntryKind $kind, ?string $description = null, ?int $appointmentId = null): LoyaltyEntry
    {
        if ($points <= 0) {
            throw DomainRuleViolation::rule('R-PONTOS', 'Debito de pontos deve ser positivo.');
        }

        return DB::transaction(function () use ($customer, $points, $kind, $description, $appointmentId) {
            $this->lock($customer->getKey());
            if ($this->balance($customer) < $points) {
                throw DomainRuleViolation::rule('R-PONTOS', 'Saldo de pontos insuficiente.');
            }

            return $this->record($customer->getKey(), -$points, $kind, $description, ['appointment_id' => $appointmentId]);
        });
    }

    /**
     * Ajuste manual (com motivo, autor e chave). Retirada nunca deixa o
     * disponivel negativo (nao "desfaz" pontos ja prometidos num resgate).
     *
     * @throws PromotionRejected
     */
    public function adjust(Customer $customer, int $points, string $reason, User $actor, string $key): LoyaltyEntry
    {
        $motivo = trim($reason);
        if ($points === 0 || abs($points) > 1_000_000) {
            throw new PromotionRejected('invalid_points');
        }
        if (mb_strlen($motivo) < 3) {
            throw new PromotionRejected('reason_required');
        }

        return DB::transaction(function () use ($customer, $points, $motivo, $actor, $key): LoyaltyEntry {
            $this->lock($customer->id);
            $existente = LoyaltyEntry::query()->where('request_key', $key)->first();
            if ($existente !== null) {
                return $existente;
            }
            if ($points < 0 && $this->available($customer) + $points < 0) {
                throw new PromotionRejected('insufficient_points', 'Disponível: '.$this->available($customer).' pontos.');
            }
            $e = $this->record($customer->id, $points, LoyaltyEntryKind::Adjustment, mb_substr($motivo, 0, 255), [
                'created_by_user_id' => $actor->id, 'request_key' => $key,
            ]);
            AuditTrail::record('loyalty.adjusted', $customer, $actor, "Ajuste de {$points} pontos.", ['pontos' => $points, 'motivo' => $motivo, 'saldo_depois' => $this->balance($customer)]);

            return $e;
        });
    }

    /**
     * Na conclusao do atendimento (dentro da transacao dela): pontos ganhos
     * (R-17, R-19) e bonus de indicacao para quem indicou (R-15), uma vez.
     */
    public function recordCompletion(Attendance $attendance): void
    {
        $politica = PromotionPolicy::current();
        if ($attendance->status !== AttendanceStatus::Completed || $attendance->customer_id === null || ! $politica->bool('loyalty_enabled')) {
            return;
        }
        $cliente = Customer::query()->find($attendance->customer_id);
        if ($cliente === null) {
            return;
        }
        $this->lock($cliente->id);

        $ganho = $politica->string('loyalty_earn_mode') === 'value'
            ? intdiv(max(0, (int) $attendance->total_cents), max(1, $politica->int('loyalty_cents_per_point')))
            : $politica->int('loyalty_points_per_visit');
        if ($ganho > 0) {
            $this->record($cliente->id, $ganho, LoyaltyEntryKind::Earned, 'Atendimento '.$attendance->code, [
                'attendance_id' => $attendance->id, 'appointment_id' => $attendance->appointment_id,
            ]);
        }

        $indicador = $cliente->referred_by_customer_id;
        $bonus = $politica->int('referral_bonus_points');
        if ($politica->bool('referral_enabled') && $indicador !== null && $bonus > 0
            && ! LoyaltyEntry::query()->where('referred_customer_id', $cliente->id)->exists()
            && ! Attendance::query()->where('customer_id', $cliente->id)->where('status', AttendanceStatus::Completed)->whereKeyNot($attendance->id)->exists()) {
            $this->lock($indicador);
            $this->record($indicador, $bonus, LoyaltyEntryKind::ReferralBonus, 'Indicação: '.$cliente->name.' fez o primeiro atendimento', [
                'referred_customer_id' => $cliente->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(int $customerId, int $points, LoyaltyEntryKind $kind, ?string $description, array $extra = []): LoyaltyEntry
    {
        return LoyaltyEntry::create([
            'customer_id' => $customerId,
            'points' => $points,
            'kind' => $kind,
            'description' => $description,
            'occurred_at' => BusinessTime::now(),
            ...$extra,
        ]);
    }
}
