<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pagamento RECEBIDO de assinatura (so inclusao): um por fatura paga
 * (gateway_payment_id = ID da fatura no Stripe, unico). Tentativa recusada ou
 * pendente vai para o historico da assinatura, nao para ca. Reembolso e
 * outro registro (SubscriptionRefund). Operacao financeira propria: nunca
 * entra no caixa fisico, na comissao nem na gorjeta.
 *
 * @property int $id
 * @property int|null $subscription_id
 * @property int $customer_id
 * @property int|null $plan_id
 * @property int|null $plan_version_id
 * @property string $gateway
 * @property string|null $gateway_payment_id
 * @property string|null $gateway_subscription_id
 * @property string|null $payment_intent_id
 * @property string|null $charge_id
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string $kind
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_at
 */
class SubscriptionPayment extends Model
{
    use AppendOnly;

    public const KINDS = ['signup' => 'Adesão', 'renewal' => 'Renovação', 'other' => 'Outra cobrança', 'adesao' => 'Adesão', 'renovacao' => 'Renovação', 'mensalidade' => 'Mensalidade'];

    protected $table = 'subscription_payments';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return HasMany<SubscriptionRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(SubscriptionRefund::class);
    }

    /** Ja reembolsado (concluido ou em andamento no Stripe). */
    public function refundedCents(): int
    {
        return (int) $this->refunds()->whereIn('status', ['succeeded', 'pending'])->sum('amount_cents');
    }

    public function refundableCents(): int
    {
        return max(0, $this->amount_cents - $this->refundedCents());
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
