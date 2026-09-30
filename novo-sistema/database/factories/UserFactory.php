<?php

namespace Database\Factories;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Recarrega do banco depois de criar: o model do teste fica igual ao que
     * a aplicacao le (inclusive colunas com valor padrao do banco), o que o
     * modo estrito do Eloquent exige.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn ($model) => $model->refresh());
    }

    protected static ?string $password;

    /**
     * Padrao: RECEPCAO (menor privilegio util), ativo. Testes que precisam
     * de mais acesso pedem explicitamente ->owner(), ->manager() etc.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => StaffRole::Reception,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(StaffRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function owner(): static
    {
        return $this->role(StaffRole::Owner);
    }

    public function manager(): static
    {
        return $this->role(StaffRole::Manager);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
