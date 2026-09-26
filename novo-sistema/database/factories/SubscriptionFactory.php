<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use App\Modules\Subscriptions\Enums\Gateway;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
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
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'gateway' => Gateway::Manual,
        ];
    }
}
