<?php

namespace Database\Factories;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cliente FICTICIO: e-mail de dominio reservado, CPF gerado com digitos
 * verificadores validos (nao pertence a ninguem de proposito), e-mail
 * confirmado. CPF e obrigatorio para o cliente desde a Fase 3.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Recarrega do banco depois de criar: o model do teste fica igual ao que
     * a aplicacao le (inclusive colunas com valor padrao do banco), o que o
     * modo estrito do Eloquent exige.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn ($model) => $model->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+55119'.fake()->unique()->numerify('########'),
            'cpf' => self::fakeCpf(),
            'password' => 'senha-de-teste-123',
            'email_verified_at' => now(),
        ];
    }

    /** Sem CPF (ex.: cliente vindo do importador): precisa completar o cadastro. */
    public function withoutCpf(): static
    {
        return $this->state(['cpf' => null]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    /** Sem senha (entra so pelo link magico ou redefinindo a senha). */
    public function withoutPassword(): static
    {
        return $this->state(['password' => null]);
    }

    /** CPF aleatorio com digitos verificadores validos. */
    public static function fakeCpf(): string
    {
        do {
            $d = array_map(fn () => random_int(0, 9), range(1, 9));
        } while (count(array_unique($d)) === 1);

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += $d[$i] * (($t + 1) - $i);
            }
            $d[$t] = ((10 * $soma) % 11) % 10;
        }

        return implode('', $d);
    }
}
