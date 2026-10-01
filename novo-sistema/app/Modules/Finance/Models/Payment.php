<?php

namespace App\Modules\Finance\Models;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AmountSource;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dinheiro recebido (ou estornado) num ATENDIMENTO (pagamentos.md). So
 * inclusao: estorno e um novo registro kind=refund que aponta o pagamento
 * original. Todo pagamento novo entra num caixa aberto (cash_session_id) e
 * gera exatamente uma movimentacao de caixa.
 *
 * @property int $id
 * @property int|null $attendance_id
 * @property int|null $customer_id
 * @property int|null $cash_session_id
 * @property PaymentKind|null $kind
 * @property PaymentMethod $method
 * @property int $amount_cents
 * @property int|null $tip_cents
 * @property int|null $refunds_payment_id
 * @property string|null $reason
 * @property string|null $request_key
 * @property int|null $received_by_user_id
 * @property Carbon|null $paid_at
 */
class Payment extends Model
{
    use AppendOnly;

    protected $table = 'payments';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'method' => PaymentMethod::class,
            'amount_source' => AmountSource::class,
            'amount_cents' => 'integer',
            'tip_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function refunds(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refunds_payment_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $p): void {
            $gorjeta = (int) ($p->tip_cents ?? 0);
            if ($p->amount_cents < 0 || $gorjeta < 0 || $p->amount_cents + $gorjeta <= 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Pagamento: valor e gorjeta nao negativos e total positivo.');
            }
            if (($p->kind === PaymentKind::Refund) !== ($p->refunds_payment_id !== null)) {
                throw DomainRuleViolation::rule('R-HIST', 'Estorno deve apontar o pagamento estornado (e so estorno aponta).');
            }
        });
    }

    /** Valor com sinal para somas: estorno subtrai. */
    public function signedAmount(): Money
    {
        $valor = Money::fromCents($this->amount_cents);

        return $this->kind === PaymentKind::Refund ? Money::zero()->subtract($valor) : $valor;
    }
}
