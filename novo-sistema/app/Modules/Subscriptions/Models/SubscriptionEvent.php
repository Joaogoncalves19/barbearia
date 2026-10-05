<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historico da assinatura (so inclusao): o que aconteceu, de que estado para
 * qual, quem originou (cliente, equipe, Stripe, sistema), motivo, data
 * efetiva e o evento do Stripe que causou, se houver.
 *
 * @property int $id
 * @property int $subscription_id
 * @property SubscriptionEventKind $kind
 * @property SubscriptionStatus|null $from_status
 * @property SubscriptionStatus|null $to_status
 * @property EventSource $source
 * @property int|null $actor_user_id
 * @property int|null $actor_customer_id
 * @property string|null $reason
 * @property Carbon|null $effective_at
 * @property int|null $gateway_event_id
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
class SubscriptionEvent extends Model
{
    use AppendOnly;

    protected $table = 'subscription_events';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SubscriptionEventKind::class,
            'from_status' => SubscriptionStatus::class,
            'to_status' => SubscriptionStatus::class,
            'source' => EventSource::class,
            'effective_at' => 'datetime',
            'data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
