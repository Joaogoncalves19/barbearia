<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionOrigin;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Assinatura ficticia manual, ativa, com direito ate daqui a um mes.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'origin' => SubscriptionOrigin::Import,
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'gateway' => Gateway::Manual,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Subscription $s): void {
            if ($s->plan_version_id === null && $s->plan_id !== null) {
                $s->plan_version_id = Plan::query()->find($s->plan_id)?->currentVersion?->id;
            }
        });
    }
}
