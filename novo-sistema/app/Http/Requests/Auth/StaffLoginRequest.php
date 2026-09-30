<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StaffLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // formulario publico; o limite de tentativas fica na rota
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nome de usuario OU e-mail no mesmo campo.
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['identifier' => 'usuário ou e-mail', 'password' => 'senha'];
    }
}
