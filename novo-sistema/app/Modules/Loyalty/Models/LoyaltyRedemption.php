<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Enums\RedemptionStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Resgate de pontos (fidelidade.md §4), decisao do dono: o cliente escolhe
 * usar os pontos ao agendar ou no balcao; os pontos SO saem do saldo na
 * conclusao do atendimento (lancamento "Resgate" no razao). Enquanto
 * reservado, os pontos ficam indisponiveis para outro resgate. Cancelamento,
 * falta ou desconto maior liberam a reserva. Nunca e apagado.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $points
 * @property RedemptionStatus $status
 * @property string|null $active_key
 * @property int|null $appointment_id
 * @property int|null $attendance_id
 * @property array<string, mixed> $reward
 * @property int $discount_cents
 * @property Carbon $reserved_at
 * @property Carbon|null $redeemed_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 */
class LoyaltyRedemption extends Model
{
    protected $table = 'loyalty_redemptions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'status' => RedemptionStatus::class,
            'reward' => 'array',
            'discount_cents' => 'integer',
            'reserved_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public static function activeKey(int $appointmentId): string
    {
        return "a{$appointmentId}";
    }

    protected static function booted(): void
    {
        static::creating(function (self $r): void {
            if ($r->points < 1 || $r->discount_cents < 0) {
                throw DomainRuleViolation::rule('R-PONTOS', 'Resgate de pontos: pontos positivos e desconto nao negativo.');
            }
        });
        static::updating(function (self $r): void {
            $antes = $r->getOriginal('status');
            $antes = $antes instanceof RedemptionStatus ? $antes : RedemptionStatus::tryFrom((string) $antes);
            if ($antes !== RedemptionStatus::Reserved && $r->isDirty('status')) {
                throw DomainRuleViolation::rule('R-PONTOS', 'Resgate de pontos so muda enquanto reservado (para usado ou liberado).');
            }
            if (array_diff(array_keys($r->getDirty()), ['status', 'active_key', 'attendance_id', 'redeemed_at', 'released_at', 'release_reason', 'updated_at']) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Resgate de pontos e historico.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Resgate de pontos e historico.'));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
