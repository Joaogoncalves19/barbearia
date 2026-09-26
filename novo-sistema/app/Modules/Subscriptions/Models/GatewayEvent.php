<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de webhook ja processado (idempotencia): unico por gateway +
 * event_id.
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
        ];
    }
}
