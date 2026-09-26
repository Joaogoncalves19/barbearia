<?php

namespace App\Modules\Finance\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Finance\Enums\AmountSource;
use App\Modules\Finance\Enums\PaymentKind;
use App\Modules\Finance\Enums\PaymentMethod;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dinheiro recebido (ou estornado). So inclusao: estorno e um novo registro
 * kind=refund.
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

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
