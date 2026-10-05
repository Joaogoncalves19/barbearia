<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Evento recebido do gateway (webhooks.md). Guardado inteiro e uma vez so
 * (unique gateway + event_id): a reentrega do mesmo evento nunca processa de
 * novo. status: received (gravado, ainda nao processado), processed, failed
 * (erro: o Stripe reenvia; pode ser reprocessado). result: applied, stale
 * (mais antigo que o ultimo aplicado), ignored (tipo sem efeito), unmatched
 * (sem assinatura local), legacy (processado pelo sistema antigo).
 *
 * @property int $id
 * @property string $gateway
 * @property string $event_id
 * @property string|null $type
 * @property string $status
 * @property string|null $result
 * @property string|null $object_type
 * @property string|null $object_id
 * @property Carbon|null $event_created_at
 * @property bool $livemode
 * @property string|null $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $received_at
 * @property Carbon|null $processed_at
 * @property int|null $subscription_id
 */
class GatewayEvent extends Model
{
    protected $table = 'gateway_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
            'event_created_at' => 'datetime',
            'livemode' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadArray(): array
    {
        $d = json_decode((string) $this->payload, true);

        return is_array($d) ? $d : [];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
