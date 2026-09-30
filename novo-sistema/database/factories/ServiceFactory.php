<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /** Recarrega do banco depois de criar (colunas com padrao do banco, ex.: lock_version). */
    public function configure(): static
    {
        return $this->afterCreating(fn ($model) => $model->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Corte '.fake()->unique()->word(), 'duration_minutes' => 30, 'price_cents' => 4500];
    }
}
