<?php

namespace Database\Factories;

use App\Modules\Loyalty\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => strtoupper(fake()->unique()->bothify('CUPOM-####')), 'discount_type' => 'percent', 'percent_bp' => 1000, 'max_uses' => 10];
    }
}
