<?php

namespace Database\Factories;

use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plano ficticio com a versao 1 (R$ 99,00 por mes, sem servicos, salvo
 * withVersion()).
 *
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
        return ['name' => 'Plano '.fake()->unique()->word(), 'is_active' => true];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Plan $plan): void {
            if (! $plan->versions()->exists()) {
                PlanVersion::query()->create([
                    'plan_id' => $plan->id, 'version' => 1, 'price_cents' => 9900, 'interval' => 'month',
                    'current_plan_id' => $plan->id, 'starts_at' => now(),
                ]);
            }
        });
    }

    /**
     * @param  list<int>  $serviceIds
     */
    public function withVersion(int $priceCents, array $serviceIds = []): static
    {
        // Roda depois do configure(): a versao padrao vira historico e esta passa a valer.
        return $this->afterCreating(function (Plan $plan) use ($priceCents, $serviceIds): void {
            $anterior = $plan->currentVersion()->first();
            $anterior?->forceFill(['current_plan_id' => null, 'ends_at' => now()->subYear()])->save();
            $v = PlanVersion::query()->create([
                'plan_id' => $plan->id, 'version' => ($anterior->version ?? 0) + 1, 'price_cents' => $priceCents, 'interval' => 'month',
                'current_plan_id' => $plan->id, 'starts_at' => now()->subYear(),
            ]);
            $v->services()->sync($serviceIds);
        });
    }
}
