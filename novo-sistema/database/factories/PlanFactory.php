<?php

namespace Database\Factories;

use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Plano '.fake()->unique()->word(), 'price_cents' => 9900];
    }
}
