<?php

namespace Database\Factories;

use App\Modules\Checkout\Enums\AttendanceSource;
use App\Modules\Checkout\Enums\AttendanceStatus;
use App\Modules\Checkout\Models\Attendance;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Atendimento cru (sem itens). Nos testes de regra, prefira abrir pelo
 * AttendanceService: e ele que monta itens, precos e historico.
 *
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => AttendanceSource::WalkIn,
            'professional_id' => Professional::factory(),
            'professional_name' => 'Profissional',
            'customer_name' => fake()->name(),
            'status' => AttendanceStatus::Open,
            'opened_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Attendance $a) => $a->refresh());
    }
}
