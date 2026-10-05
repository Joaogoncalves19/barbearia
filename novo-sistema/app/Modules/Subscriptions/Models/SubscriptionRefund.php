<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Shared\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Reembolso de um pagamento de assinatura (so inclusao). O pagamento original
 * nunca e apagado nem editado; o reembolso aponta para ele com valor, motivo,
 * data, origem (equipe ou Stripe), ID externo e quem pediu.
 *
 * @property int $id
 * @property int $subscription_payment_id
 * @property int|null $subscription_id
 * @property int $customer_id
 * @property int $amount_cents
 * @property string $status
 * @property string|null $reason
 * @property string $source
 * @property string|null $gateway_refund_id
 * @property int|null $requested_by_user_id
 * @property string|null $request_key
 * @property Carbon|null $refunded_at
 */
class SubscriptionRefund extends Model
{
    use AppendOnly;

    protected $table = 'subscription_refunds';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * So a situacao no Stripe (pendente -> concluido/falhou) muda depois.
     *
     * @var list<string>
     */
    protected array $appendOnlyMutable = ['status', 'refunded_at', 'gateway_refund_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }
}
