<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->word(), 'sort_order' => 0];
    }
}
