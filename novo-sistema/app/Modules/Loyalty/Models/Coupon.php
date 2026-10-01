<?php

namespace App\Modules\Loyalty\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Pricing\Discount;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Cupom de desconto (promocoes.md §3). Codigo unico, sempre em maiusculas.
 * percent: percent_bp 1..10000 (sem valor fixo); fixed: amount_cents > 0.
 *
 * uses_count = usos reservados ou feitos (inclui os do sistema antigo, que
 * nao guardava todos os usos por cliente); muda SO pelo CouponRedemptions,
 * com a linha do cupom travada (version). Alterar o cupom nao muda os
 * agendamentos que ja o usaram: eles guardam a propria regra.
 *
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property DiscountType|null $discount_type
 * @property int|null $percent_bp
 * @property int|null $amount_cents
 * @property int|null $max_uses
 * @property int $uses_count
 * @property Carbon|null $expires_on
 * @property bool $is_active
 * @property int $version
 * @property int|null $created_by_user_id
 */
#[UseFactory(CouponFactory::class)]
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'coupons';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['version', 'uses_count'];

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
            'version' => 'integer',
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
            if ($c->code === '' || ! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/', $c->code)) {
                throw DomainRuleViolation::rule('R-CUPOM', 'Codigo do cupom: 3 a 64 letras, numeros, hifen ou sublinhado.');
            }
            if ($c->max_uses !== null && $c->max_uses < 1) {
                throw DomainRuleViolation::rule('R-CUPOM', 'Limite de usos deve ser ao menos 1 (ou vazio para ilimitado).');
            }
        });
    }

    /** A regra de calculo do cupom (a mesma de qualquer desconto). */
    public function rule(): Discount
    {
        return Discount::of($this->discount_type ?? DiscountType::Percent, (int) ($this->discount_type === DiscountType::Fixed ? $this->amount_cents : $this->percent_bp));
    }

    public function describe(): string
    {
        return $this->rule()->label();
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
