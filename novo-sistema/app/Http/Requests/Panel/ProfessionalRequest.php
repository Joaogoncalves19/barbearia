<?php

namespace App\Http\Requests\Panel;

use App\Http\Requests\Panel\Concerns\CatalogRules;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Criar/editar profissional. A rota exige professionals.create/update; aqui:
 * apresentacao publica (bio, destaque, site, foto) so muda com
 * professionals.display. Conta de acesso: OPCIONAL. Ligar uma conta ja
 * existente e ainda sem profissional (user_id), ou, so para quem tem
 * users.manage (Fase 12.5, como no sistema antigo), criar o login aqui mesmo
 * (access_*) ou dar uma nova senha provisoria a conta ligada.
 */
class ProfessionalRequest extends FormRequest
{
    use CatalogRules;

    private const DISPLAY_FIELDS = ['headline', 'bio', 'is_public', 'is_featured'];

    /** Campos de acesso ao painel: so com users.manage. */
    private const ACCESS_FIELDS = ['access_username', 'access_email', 'access_password', 'access_reset_password'];

    protected function prepareForValidation(): void
    {
        if ($this->filled('access_username')) {
            $this->merge(['access_username' => User::normalizeUsername($this->string('access_username')->value())]);
        }
        if ($this->filled('access_email')) {
            $this->merge(['access_email' => User::normalizeEmail($this->string('access_email')->value())]);
        }
    }

    public function authorize(): bool
    {
        if (collect(self::ACCESS_FIELDS)->contains(fn ($c) => $this->filled($c)) && ! $this->user('web')?->can('users.manage')) {
            return false;
        }

        $p = $this->professional();
        $podeExibicao = (bool) $this->user('web')?->can('professionals.display');

        if ($p === null) {
            $enviouExibicao = collect(self::DISPLAY_FIELDS)->contains(fn ($c) => $this->filled($c) && $this->input($c) !== '0') || $this->hasFile('photo');

            return ! ($enviouExibicao && ! $podeExibicao);
        }

        foreach (['headline', 'bio'] as $campo) {
            if ($this->changesWithoutAbility($campo, (string) $p->{$campo}, 'professionals.display')) {
                return false;
            }
        }
        foreach (['is_public', 'is_featured'] as $campo) {
            if ($this->changesWithoutAbility($campo, (bool) $p->{$campo}, 'professionals.display')) {
                return false;
            }
        }

        return ! (($this->hasFile('photo') || $this->boolean('remove_photo')) && ! $podeExibicao);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $p = $this->professional();

        return [
            'display_name' => ['required', 'string', 'min:2', 'max:80', $this->uniqueName('professionals', 'display_name', $p?->id, 'Já existe um profissional com este nome de exibição.')],
            'user_id' => ['nullable', 'integer', function (string $a, mixed $v, Closure $fail) use ($p): void {
                if (! User::query()->whereKey($v)->exists()) {
                    $fail('Conta de acesso inválida.');
                } elseif (Professional::withTrashed()->where('user_id', $v)->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
                    $fail('Esta conta de acesso já está ligada a outro profissional.');
                }
            }],
            // Login criado aqui (Fase 12.5): as mesmas regras da tela Usuarios.
            'access_username' => ['nullable', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username'),
                'prohibits:user_id', 'required_with:access_password,access_email',
                function (string $a, mixed $v, Closure $fail) use ($p): void {
                    if ($p?->user_id !== null) {
                        $fail('Este profissional já tem uma conta de acesso.');
                    }
                }],
            'access_email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'access_password' => ['nullable', 'required_with:access_username', 'string', Password::defaults()],
            'access_reset_password' => ['nullable', 'string', Password::defaults(), function (string $a, mixed $v, Closure $fail) use ($p): void {
                if ($p?->user_id === null) {
                    $fail('Este profissional ainda não tem conta de acesso.');
                }
            }],
            'is_bookable' => ['sometimes', 'boolean'],
            'headline' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'file', ...ImageStore::rules()],
            'remove_photo' => ['sometimes', 'boolean'],
            'version' => $p === null ? ['prohibited'] : ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => 'nome de exibição', 'user_id' => 'conta de acesso', 'headline' => 'especialidade',
            'access_username' => 'usuário', 'access_email' => 'e-mail', 'access_password' => 'senha provisória',
            'access_reset_password' => 'nova senha provisória',
            'bio' => 'apresentação', 'photo' => 'foto',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_username.regex' => 'Use só letras minúsculas, números, ponto, hífen ou sublinhado (sem espaços e sem @).',
            'access_username.prohibits' => 'Escolha: criar um acesso novo OU ligar uma conta que já existe.',
            'access_username.required_with' => 'Informe o usuário para entrar no painel.',
            'access_password.required_with' => 'Informe a senha provisória (o profissional troca no primeiro acesso).',
        ];
    }

    /**
     * Login novo pedido no formulario (null = nao criar).
     *
     * @return array{name: string, username: string, email: ?string}|null
     */
    public function accessData(): ?array
    {
        if (! $this->filled('access_username')) {
            return null;
        }

        return [
            'name' => $this->string('display_name')->squish()->value(),
            'username' => (string) $this->input('access_username'),
            'email' => $this->input('access_email') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function professionalData(): array
    {
        $dados = [
            'display_name' => $this->string('display_name')->squish()->value(),
            'user_id' => $this->filled('user_id') ? $this->integer('user_id') : null,
        ];

        if ($this->has('is_bookable')) {
            $dados['is_bookable'] = $this->boolean('is_bookable');
        }
        foreach (['headline', 'bio'] as $campo) {
            if ($this->has($campo)) {
                $dados[$campo] = $this->filled($campo) ? $this->string($campo)->trim()->value() : null;
            }
        }
        foreach (['is_public', 'is_featured'] as $campo) {
            if ($this->has($campo)) {
                $dados[$campo] = $this->boolean($campo);
            }
        }

        return $dados;
    }

    private function professional(): ?Professional
    {
        $p = $this->route('professional');

        return $p instanceof Professional ? $p : null;
    }
}
