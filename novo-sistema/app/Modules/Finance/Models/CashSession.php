<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\CashSessionStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um periodo de caixa: da abertura (valor inicial em dinheiro) ao fechamento
 * (valor esperado, contado e diferenca). UM aberto por barbearia de cada vez
 * (open_marker unico). Fechado, nao muda mais. Movimentacoes so pelo
 * CashRegister (caixa.md).
 *
 * @property int $id
 * @property int|null $open_marker
 * @property CashSessionStatus $status
 * @property int|null $opened_by_user_id
 * @property Carbon $opened_at
 * @property int $opening_float_cents
 * @property string|null $opening_notes
 * @property int|null $closed_by_user_id
 * @property Carbon|null $closed_at
 * @property int|null $expected_cash_cents
 * @property int|null $counted_cash_cents
 * @property int|null $difference_cents
 * @property string|null $closing_notes
 * @property int $version
 */
class CashSession extends Model
{
    use Auditable;

    protected $table = 'cash_sessions';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['version', 'open_marker'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'difference_cents' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            if ($s->opening_float_cents < 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Valor inicial do caixa nao pode ser negativo.');
            }
            if (($s->status === CashSessionStatus::Open) !== ($s->open_marker === 1)) {
                throw DomainRuleViolation::rule('R-CAIXA', 'Marcador de caixa aberto inconsistente com o status.');
            }
        });

        static::updating(function (self $s): void {
            if ($s->getRawOriginal('status') === CashSessionStatus::Closed->value
                && array_diff(array_keys($s->getDirty()), ['version', 'updated_at']) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Caixa fechado nao pode ser alterado.');
            }
        });

        static::deleting(function (): void {
            throw DomainRuleViolation::rule('R-HIST', 'Caixa nao e apagado.');
        });
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
