<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\AdvanceKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Vale (adiantamento ao profissional), abatido no repasse (repasses.md §4).
 * So inclusao: vale = valor positivo; estorno de vale = registro novo,
 * negativo, apontando o vale (uma vez). Pago em dinheiro, sai do caixa.
 * So o vinculo com o repasse muda depois.
 *
 * @property int $id
 * @property int $professional_id
 * @property AdvanceKind $kind
 * @property int $amount_cents
 * @property Carbon|null $issued_on
 * @property string|null $reference_month
 * @property string|null $description
 * @property int|null $reverses_advance_id
 * @property PaymentMethod|null $method
 * @property int|null $cash_session_id
 * @property int|null $created_by_user_id
 * @property string|null $request_key
 * @property int|null $commission_payout_id
 * @property Carbon|null $occurred_at
 * @property bool $is_legacy
 */
class Advance extends Model
{
    use AppendOnly;

    protected $table = 'advances';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $appendOnlyMutable = ['commission_payout_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdvanceKind::class,
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'issued_on' => 'date',
            'occurred_at' => 'datetime',
            'is_legacy' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a): void {
            $a->kind ??= AdvanceKind::Advance;
            $ok = $a->kind === AdvanceKind::Advance
                ? $a->amount_cents > 0 && $a->reverses_advance_id === null
                : $a->amount_cents < 0 && $a->reverses_advance_id !== null;
            if (! $ok) {
                throw DomainRuleViolation::rule('R-VALE', 'Vale positivo; estorno de vale negativo apontando o vale.');
            }
        });
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CommissionPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'commission_payout_id');
    }

    /**
     * @return HasOne<Advance, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Advance::class, 'reverses_advance_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
