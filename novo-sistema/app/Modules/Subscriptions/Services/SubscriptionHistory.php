<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\SubscriptionEventKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionEvent;
use Carbon\CarbonInterface;

/** Grava o historico da assinatura (so inclusao), dentro da transacao do chamador. */
final class SubscriptionHistory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function record(
        Subscription $subscription,
        SubscriptionEventKind $kind,
        EventSource $source,
        ?SubscriptionStatus $from = null,
        ?SubscriptionStatus $to = null,
        User|Customer|null $actor = null,
        ?string $reason = null,
        ?CarbonInterface $effectiveAt = null,
        ?int $gatewayEventId = null,
        array $data = [],
    ): SubscriptionEvent {
        return SubscriptionEvent::query()->create([
            'subscription_id' => $subscription->id,
            'kind' => $kind,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'actor_customer_id' => $actor instanceof Customer ? $actor->id : null,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'effective_at' => $effectiveAt ?? BusinessTime::now(),
            'gateway_event_id' => $gatewayEventId,
            'data' => $data === [] ? null : $data,
        ]);
    }
}
