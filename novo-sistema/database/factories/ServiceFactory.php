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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Corte '.fake()->unique()->word(), 'duration_minutes' => 30, 'price_cents' => 4500];
    }
}
