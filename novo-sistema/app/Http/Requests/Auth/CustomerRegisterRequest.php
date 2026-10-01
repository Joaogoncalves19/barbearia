<?php

namespace App\Http\Requests\Auth;

use App\Modules\Customers\Support\Cpf;
use App\Modules\Customers\Support\Phone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro do cliente. CPF OBRIGATORIO e valido (digitos verificadores).
 *
 * Unicidade de e-mail/CPF/telefone NAO e validada aqui: um erro "e-mail ja
 * cadastrado" revelaria quem e cliente. Quem trata e CustomerRegistration.
 */
class CustomerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'cpf' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                if (Cpf::normalize(is_string($value) ? $value : null) === null) {
                    $fail('Informe um CPF válido.');
                }
            }],
            'phone' => ['nullable', 'string', 'max:25', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && Phone::normalize($value) === null) {
                    $fail('Informe um celular com DDD.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'marketing' => ['sometimes', 'boolean'],
            // Fase 8: codigo de quem indicou (opcional). Codigo inexistente e
            // ignorado sem aviso (o cadastro nao revela se um codigo existe).
            'referral' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'email' => 'e-mail', 'cpf' => 'CPF', 'phone' => 'celular', 'password' => 'senha'];
    }

    /**
     * @return array{name: string, email: string, cpf: string, phone: ?string, password: string, marketing: bool}
     */
    public function registrationData(): array
    {
        $telefone = trim($this->string('phone')->value());

        return [
            'name' => $this->string('name')->trim()->value(),
            'email' => $this->string('email')->trim()->value(),
            'cpf' => (string) Cpf::normalize($this->string('cpf')->value()),
            'phone' => $telefone === '' ? null : Phone::normalize($telefone),
            'password' => $this->string('password')->value(),
            'marketing' => $this->boolean('marketing'),
            'referral' => mb_strtoupper(trim($this->string('referral')->value())) ?: null,
        ];
    }
}
