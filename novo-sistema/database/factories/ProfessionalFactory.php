<?php

namespace Database\Factories;

use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

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
        return ['display_name' => fake()->firstName(), 'commission_rate_bp' => 4000, 'is_active' => true, 'is_bookable' => true];
    }
}
