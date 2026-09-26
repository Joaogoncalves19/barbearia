<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assinatura de plano (com historico: o cliente pode ter varias ao longo
 * do tempo, mas so UMA vigente). active_customer_id e a sentinela do indice
 * unico: preenchida enquanto a assinatura vale (ativa ou com cancelamento
 * agendado), nula depois.
 *
 * @property SubscriptionStatus|null $status
 * @property int $customer_id
 * @property int|null $active_customer_id
 */
#[UseFactory(SubscriptionFactory::class)]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $table = 'subscriptions';

    protected $guarded = ['id', 'active_customer_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'gateway' => Gateway::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            $s->active_customer_id = $s->status?->isCurrent() ? $s->customer_id : null;
        });
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
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
