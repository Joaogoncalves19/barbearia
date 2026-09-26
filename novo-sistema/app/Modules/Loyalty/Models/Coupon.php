<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cupom de desconto. Codigo unico, sempre em maiusculas.
 * percent: percent_bp 1..10000 (sem valor fixo); fixed: amount_cents > 0.
 *
 * @property DiscountType|null $discount_type
 * @property int|null $percent_bp
 * @property int|null $amount_cents
 */
#[UseFactory(CouponFactory::class)]
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'coupons';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'percent_bp' => 'integer',
            'amount_cents' => 'integer',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'expires_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    protected static function booted(): void
    {
        static::saving(function (self $c): void {
            $c->code = static::normalizeCode((string) $c->code);

            $valido = match ($c->discount_type) {
                DiscountType::Percent => $c->percent_bp !== null && $c->percent_bp >= 1 && $c->percent_bp <= 10000 && $c->amount_cents === null,
                DiscountType::Fixed => $c->amount_cents !== null && $c->amount_cents > 0 && $c->percent_bp === null,
                default => false,
            };
            if (! $valido) {
                throw DomainRuleViolation::rule('R-CUPOM', 'Cupom percentual exige 0,01% a 100%; cupom de valor fixo exige valor positivo.');
            }
        });
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
