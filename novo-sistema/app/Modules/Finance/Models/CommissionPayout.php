<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Repasse ao profissional (repasses.md §5): fecha os lancamentos em aberto
 * ate um instante (comissao + gorjeta - vales) e registra o pagamento.
 * snapshot guarda o que entrou (ids e valores), para o historico mesmo
 * depois de um estorno. Repasse importado do sistema antigo tem snapshot
 * nulo e nao tem lancamentos.
 *
 * Imutavel depois de criado; a unica mudanca e o estorno (uma vez), que
 * devolve os lancamentos ao saldo em aberto.
 *
 * @property int $id
 * @property int $professional_id
 * @property int $amount_cents
 * @property int|null $commission_cents
 * @property int|null $tip_cents
 * @property int|null $advances_cents
 * @property int|null $services_total_cents
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $cutoff_at
 * @property string|null $reference_month
 * @property Carbon|null $paid_on
 * @property PaymentMethod|null $method
 * @property int|null $cash_session_id
 * @property array<string, mixed>|null $snapshot
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property string|null $request_key
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by_user_id
 * @property string|null $reversal_reason
 * @property int|null $reversal_cash_session_id
 * @property string|null $reversal_request_key
 */
class CommissionPayout extends Model
{
    protected $table = 'commission_payouts';

    protected $guarded = ['id'];

    private const REVERSAL = ['reversed_at', 'reversed_by_user_id', 'reversal_reason', 'reversal_cash_session_id', 'reversal_request_key', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'commission_cents' => 'integer',
            'tip_cents' => 'integer',
            'advances_cents' => 'integer',
            'services_total_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'cutoff_at' => 'datetime',
            'paid_on' => 'date',
            'method' => PaymentMethod::class,
            'snapshot' => 'array',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $p): void {
            $alteradas = array_diff(array_keys($p->getDirty()), self::REVERSAL);
            if ($alteradas !== [] || $p->getOriginal('reversed_at') !== null) {
                throw DomainRuleViolation::rule('R-HIST', 'Repasse e historico: so pode ser estornado, uma vez.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Repasse e historico e nao pode ser apagado.'));
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Repasse do sistema antigo: sem lancamentos, so o valor pago. */
    public function isLegacy(): bool
    {
        return $this->snapshot === null;
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return HasMany<CommissionEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    /**
     * @return HasMany<TipEntry, $this>
     */
    public function tips(): HasMany
    {
        return $this->hasMany(TipEntry::class);
    }

    /**
     * @return HasMany<Advance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
