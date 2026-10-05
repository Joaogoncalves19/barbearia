<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Subscriptions\Enums\EventSource;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Assinatura de plano (assinaturas.md), com historico: o cliente pode ter
 * varias ao longo do tempo, mas so UMA vigente (aguardando pagamento, ativa,
 * em atraso ou com cancelamento agendado). active_customer_id e a sentinela
 * do indice unico.
 *
 * Estado (status) e direito ao beneficio sao coisas separadas: o direito vem
 * da data paga (`ends_on`, inclusive) e e decidido so pelo
 * SubscriptionBenefits. Nenhuma tela grava o estado direto: so os servicos,
 * e so nas transicoes permitidas (SubscriptionStatus::canTransitionTo).
 * Nunca e apagada.
 *
 * @property int $id
 * @property string $public_id
 * @property int $customer_id
 * @property int|null $plan_id
 * @property int|null $plan_version_id
 * @property SubscriptionStatus|null $status
 * @property SubscriptionOrigin $origin
 * @property Gateway $gateway
 * @property CarbonInterface|null $starts_on
 * @property CarbonInterface|null $ends_on
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $gateway_customer_id
 * @property string|null $gateway_subscription_id
 * @property string|null $gateway_status
 * @property string|null $last_gateway_payment_id
 * @property int|null $active_customer_id
 * @property int|null $signup_appointment_id
 * @property int|null $created_by_user_id
 * @property string|null $checkout_session_id
 * @property string|null $checkout_url
 * @property CarbonInterface|null $checkout_expires_at
 * @property CarbonInterface|null $activated_at
 * @property bool $cancel_at_period_end
 * @property CarbonInterface|null $cancel_requested_at
 * @property EventSource|null $cancel_source
 * @property string|null $cancel_reason
 * @property int|null $cancelled_by_user_id
 * @property CarbonInterface|null $cancel_effective_on
 * @property CarbonInterface|null $gateway_period_end_at
 * @property CarbonInterface|null $gateway_synced_at
 * @property int $version
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
            'origin' => SubscriptionOrigin::class,
            'gateway' => Gateway::class,
            'cancel_source' => EventSource::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancel_requested_at' => 'datetime',
            'cancel_effective_on' => 'date',
            'gateway_period_end_at' => 'datetime',
            'gateway_synced_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s): void {
            $s->public_id ??= (string) Str::uuid();
        });
        static::saving(function (self $s): void {
            $s->active_customer_id = $s->status?->isCurrent() ? $s->customer_id : null;
            if ($s->exists && $s->isDirty('status')) {
                $de = SubscriptionStatus::tryFrom((string) $s->getRawOriginal('status'));
                if ($de !== null && $s->status !== null && ! $de->canTransitionTo($s->status)) {
                    throw DomainRuleViolation::rule('R-SUB', "Assinatura não pode passar de \"{$de->label()}\" para \"{$s->status->label()}\".");
                }
            }
            foreach (['customer_id', 'public_id', 'plan_version_id', 'origin'] as $fixo) {
                if ($s->exists && $s->isDirty($fixo) && $s->getRawOriginal($fixo) !== null) {
                    throw DomainRuleViolation::rule('R-SUB', "Campo {$fixo} da assinatura não muda.");
                }
            }
            if ($s->exists && $s->isDirty('gateway_subscription_id') && $s->getRawOriginal('gateway_subscription_id') !== null) {
                throw DomainRuleViolation::rule('R-SUB', 'O ID da assinatura no Stripe nunca é substituído.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Assinatura é histórico e não pode ser apagada.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isStripe(): bool
    {
        return $this->gateway === Gateway::Stripe;
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
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function signupAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'signup_appointment_id');
    }

    /**
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /**
     * @return HasMany<SubscriptionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<SubscriptionRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(SubscriptionRefund::class);
    }

    public function planName(): string
    {
        return $this->plan->name ?? 'Plano removido';
    }
}
