<?php

namespace App\Modules\Checkout\Models;

use App\Modules\Checkout\Models\Concerns\FrozenWithAttendance;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Pricing\Discount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desconto do atendimento: tipo (percentual ou valor), origem (kind: manual,
 * vindo do agendamento...), valor antes (base_cents), valor descontado
 * (amount_cents), motivo e quem aplicou. O valor descontado e calculado SO
 * pelo PriceBreakdown (AttendancePricing) e congela na conclusao.
 *
 * @property int $id
 * @property int $attendance_id
 * @property AdjustmentKind $kind
 * @property DiscountType $type
 * @property int|null $percent_bp
 * @property int|null $fixed_cents
 * @property int $base_cents
 * @property int $amount_cents
 * @property string|null $reason
 * @property int|null $applied_by_user_id
 * @property int|null $coupon_redemption_id
 * @property int|null $loyalty_redemption_id
 * @property int|null $subscription_id
 */
class AttendanceDiscount extends Model
{
    use Auditable, FrozenWithAttendance;

    protected $table = 'attendance_discounts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdjustmentKind::class,
            'type' => DiscountType::class,
            'percent_bp' => 'integer',
            'fixed_cents' => 'integer',
            'base_cents' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $d): void {
            if ($d->amount_cents < 0 || $d->base_cents < 0 || $d->amount_cents > $d->base_cents) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Desconto: valor nao negativo e nunca maior que a base.');
            }
            $percentual = $d->type === DiscountType::Percent;
            if ($percentual !== ($d->percent_bp !== null) || $percentual === ($d->fixed_cents !== null)) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Desconto percentual guarda so o percentual; em valor, so o valor.');
            }
        });
    }

    /** A regra de calculo deste desconto. */
    public function rule(): Discount
    {
        return Discount::of($this->type, (int) ($this->type === DiscountType::Percent ? $this->percent_bp : $this->fixed_cents));
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
