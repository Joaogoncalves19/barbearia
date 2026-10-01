<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Loyalty\Models\Coupon;
use App\Modules\Loyalty\Models\GiftCard;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desconto aplicado ao agendamento, com a origem. amount_cents e sempre >= 0.
 * Fase 8: guarda a regra (tipo e valor) e a reserva de cupom/pontos.
 *
 * @property int $id
 * @property int $appointment_id
 * @property AdjustmentKind $kind
 * @property int $amount_cents
 * @property DiscountType|null $discount_type
 * @property int|null $percent_bp
 * @property int|null $fixed_cents
 * @property int|null $coupon_id
 * @property int|null $coupon_redemption_id
 * @property int|null $loyalty_redemption_id
 * @property string|null $description
 */
class AppointmentAdjustment extends Model
{
    protected $table = 'appointment_adjustments';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdjustmentKind::class,
            'amount_cents' => 'integer',
            'discount_type' => DiscountType::class,
            'percent_bp' => 'integer',
            'fixed_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $a): void {
            if ($a->amount_cents < 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Desconto nao pode ser negativo.');
            }
        });
    }
}
