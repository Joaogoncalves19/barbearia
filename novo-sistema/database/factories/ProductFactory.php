<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => 'Pomada '.fake()->unique()->word(), 'price_cents' => 3500, 'cost_cents' => 1500, 'unit' => 'un'];
    }

    /** Insumo: usado no servico, nao vendido. */
    public function supply(): static
    {
        return $this->state(['name' => 'Lâmina '.fake()->unique()->word(), 'price_cents' => null, 'cost_cents' => 80]);
    }

    public function configure(): static
    {
        // Recarrega para trazer os padroes do banco (lock_version, stock_version).
        return $this->afterCreating(fn (Product $p) => $p->refresh());
    }
}
