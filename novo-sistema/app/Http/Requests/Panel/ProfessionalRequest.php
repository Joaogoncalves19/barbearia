<?php

namespace App\Http\Requests\Panel;

use App\Http\Requests\Panel\Concerns\CatalogRules;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Team\Models\Professional;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Criar/editar profissional. A rota exige professionals.create/update; aqui:
 * apresentacao publica (bio, destaque, site, foto) so muda com
 * professionals.display. Conta de acesso: OPCIONAL, so uma conta ja
 * existente e ainda sem profissional (nunca cria login).
 */
class ProfessionalRequest extends FormRequest
{
    use CatalogRules;

    private const DISPLAY_FIELDS = ['headline', 'bio', 'is_public', 'is_featured'];

    public function authorize(): bool
    {
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
            'is_bookable' => ['sometimes', 'boolean'],
            'headline' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'file', ...ImageStore::RULES],
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
            'bio' => 'apresentação', 'photo' => 'foto',
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
