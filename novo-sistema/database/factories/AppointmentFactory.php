<?php

namespace Database\Factories;

use App\Modules\Scheduling\Enums\AppointmentSource;
use App\Modules\Scheduling\Enums\AppointmentStatus;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $inicio = now()->addDays(2)->setTime(13, 0);

        return [
            'professional_id' => Professional::factory(),
            'professional_name' => 'Profissional',
            'customer_name' => fake()->name(),
            'starts_at' => $inicio,
            'ends_at' => $inicio->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'source' => AppointmentSource::Staff,
        ];
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
