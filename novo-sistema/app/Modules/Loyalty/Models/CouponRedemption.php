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
 * Uso de cupom (promocoes.md §4): reservado ao agendar/aplicar, usado na
 * conclusao, liberado no cancelamento/falta/substituicao. active_key (unico)
 * vale "c{cupom}|u{cliente}" enquanto reservado ou usado: 1 uso por cliente.
 * Nunca e apagado; so avanca de estado (reservado -> usado | liberado).
 *
 * @property int $id
 * @property int $coupon_id
 * @property int|null $customer_id
 * @property int|null $appointment_id
 * @property int|null $attendance_id
 * @property RedemptionStatus $status
 * @property string|null $active_key
 * @property int|null $discount_cents
 * @property Carbon|null $reserved_at
 * @property Carbon|null $redeemed_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 */
class CouponRedemption extends Model
{
    protected $table = 'coupon_redemptions';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'discount_cents' => 'integer',
            'reserved_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public static function activeKey(int $couponId, int $customerId): string
    {
        return "c{$couponId}|u{$customerId}";
    }

    protected static function booted(): void
    {
        static::updating(function (self $r): void {
            $antes = $r->getOriginal('status');
            $antes = $antes instanceof RedemptionStatus ? $antes : RedemptionStatus::tryFrom((string) $antes);
            if ($antes !== RedemptionStatus::Reserved && $r->isDirty('status')) {
                throw DomainRuleViolation::rule('R-CUPOM', 'Uso de cupom so muda enquanto reservado (para usado ou liberado).');
            }
            if (array_diff(array_keys($r->getDirty()), ['status', 'active_key', 'attendance_id', 'redeemed_at', 'released_at', 'release_reason']) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Uso de cupom e historico.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Uso de cupom e historico.'));
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
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
