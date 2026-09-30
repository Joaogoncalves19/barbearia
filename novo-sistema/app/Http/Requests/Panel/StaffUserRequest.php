<?php

namespace App\Http\Requests\Panel;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

/**
 * Criar/editar usuario da equipe. A AUTORIZACAO esta na rota
 * (can:users.manage + can:update,user) e na UserPolicy; aqui so a entrada.
 */
class StaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ja autorizado pelos middlewares can: da rota
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => User::normalizeUsername($this->string('username')->value()),
            'email' => User::normalizeEmail($this->string('email')->value()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $alvo = $this->route('user');
        $ignorar = $alvo instanceof User ? $alvo->id : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // Usuario: letras minusculas, numeros, ponto, hifen e sublinhado.
            // Sem "@", para nunca ser confundido com e-mail no login.
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($ignorar)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignorar)],
            // Na edicao, papel/status ausentes = sem mudanca (o formulario
            // nem mostra esses campos quando a pessoa edita a propria conta).
            'role' => [$ignorar === null ? 'required' : 'sometimes', new Enum(StaffRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            'temporary_password' => $ignorar === null
                ? ['required', 'string', Password::defaults()]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['username.regex' => 'Use só letras minúsculas, números, ponto, hífen ou sublinhado (sem espaços e sem @).'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome', 'username' => 'usuário', 'email' => 'e-mail', 'role' => 'papel',
            'temporary_password' => 'senha provisória',
        ];
    }

    /**
     * @return array{name: string, username: string, email: ?string}
     */
    public function accountData(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'username' => (string) $this->input('username'),
            'email' => $this->input('email') ?: null,
        ];
    }

    public function role(): ?StaffRole
    {
        return $this->filled('role') ? StaffRole::from($this->string('role')->value()) : null;
    }
}
