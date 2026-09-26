<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Combo '.fake()->unique()->word(), 'price_cents' => 7000];
    }
}
