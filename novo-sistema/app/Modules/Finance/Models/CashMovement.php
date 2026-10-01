<?php

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\CashMovementType;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Razao do caixa (so inclusao): cada entrada ou saida com tipo, forma,
 * valor com sinal, origem (payment_id quando veio de um pagamento ou
 * estorno), responsavel, data e descricao. Corrige-se com outro lancamento.
 *
 * @property int $id
 * @property int $cash_session_id
 * @property CashMovementType $type
 * @property PaymentMethod $method
 * @property int $amount_cents
 * @property int|null $payment_id
 * @property int|null $commission_payout_id
 * @property int|null $advance_id
 * @property int|null $gift_card_id
 * @property string $description
 * @property string|null $request_key
 * @property int|null $created_by_user_id
 * @property Carbon $occurred_at
 */
class CashMovement extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'cash_movements';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'method' => PaymentMethod::class,
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $m): void {
            if ($m->amount_cents === 0 || ($m->amount_cents > 0) !== $m->type->isInflow()) {
                throw DomainRuleViolation::rule('R-CAIXA', 'Movimentacao de caixa: entrada positiva, saida negativa, nunca zero.');
            }
            // Exatamente a origem do tipo (pagamento, repasse ou vale), ou nenhuma (manual).
            $origem = $m->type->originColumn();
            foreach (['payment_id', 'commission_payout_id', 'advance_id', 'gift_card_id'] as $coluna) {
                if (($coluna === $origem) !== ($m->getAttribute($coluna) !== null)) {
                    throw DomainRuleViolation::rule('R-CAIXA', 'Movimentacao de caixa aponta a sua origem (pagamento, repasse ou vale); suprimento e sangria nao apontam nenhuma.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
