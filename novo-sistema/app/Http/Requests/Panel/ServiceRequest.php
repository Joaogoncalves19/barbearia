<?php

namespace App\Http\Requests\Panel;

use App\Http\Requests\Panel\Concerns\CatalogRules;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceCategory;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Support\Duration;
use App\Modules\Shared\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * Criar/editar servico. A rota exige services.create / services.update; aqui:
 *
 * - preco (services.price) e exibicao (services.display) so mudam com a
 *   habilidade propria; campo adulterado sem ela = 403;
 * - preco digitado como dinheiro ("45,00"), convertido UMA vez para centavos
 *   pelo Money (sem float);
 * - duracao em minutos pela regra unica do Duration.
 */
class ServiceRequest extends FormRequest
{
    use CatalogRules;

    private const DISPLAY_FIELDS = ['is_public', 'is_featured'];

    public function authorize(): bool
    {
        $s = $this->service();

        if ($s === null) {
            // Criar ja define o preco inicial (services.create). Exibicao,
            // se enviada, exige services.display.
            return ! (($this->has('is_public') || $this->has('is_featured') || $this->hasFile('image'))
                && ! (bool) $this->user('web')?->can('services.display'));
        }

        $precoNovo = $this->has('price') ? $this->priceCents() : null;
        if ($precoNovo !== null && $precoNovo !== $s->price_cents && ! (bool) $this->user('web')?->can('services.price')) {
            return false;
        }

        foreach (self::DISPLAY_FIELDS as $campo) {
            if ($this->changesWithoutAbility($campo, (bool) $s->{$campo}, 'services.display')) {
                return false;
            }
        }

        return ! (($this->hasFile('image') || $this->boolean('remove_image')) && ! (bool) $this->user('web')?->can('services.display'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $s = $this->service();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120', $this->uniqueName('services', 'name', $s?->id, 'Já existe um serviço com este nome.')],
            'category_id' => ['required', 'integer', function (string $a, mixed $v, Closure $fail) use ($s): void {
                $cat = ServiceCategory::query()->find($v);
                // Categoria inativa so e aceita se ja era a do servico.
                if ($cat === null || (! $cat->is_active && $cat->id !== $s?->category_id)) {
                    $fail('Escolha uma categoria ativa.');
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', function (string $a, mixed $v, Closure $fail): void {
                if (! Duration::isValid((int) $v)) {
                    $fail('Escolha uma duração entre '.Duration::STEP_MINUTES.' min e '.Duration::format(Duration::MAX_MINUTES).', em passos de '.Duration::STEP_MINUTES.' min.');
                }
            }],
            'price' => [$s === null ? 'required' : 'sometimes', 'string', 'max:20', function (string $a, mixed $v, Closure $fail): void {
                $c = $this->priceCents();
                if ($c === null || $c < Service::MIN_PRICE_CENTS || $c > Service::MAX_PRICE_CENTS) {
                    $fail('Informe um preço entre '.Money::fromCents(Service::MIN_PRICE_CENTS)->format().' e '.Money::fromCents(Service::MAX_PRICE_CENTS)->format().' (ex.: 45,00).');
                }
            }],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', ...ImageStore::rules()],
            'remove_image' => ['sometimes', 'boolean'],
            'version' => $s === null ? ['prohibited'] : ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome', 'category_id' => 'categoria', 'description' => 'descrição',
            'duration_minutes' => 'duração', 'price' => 'preço', 'image' => 'imagem',
        ];
    }

    /**
     * Campos a gravar. Ausente = mantem o valor atual.
     *
     * @return array<string, mixed>
     */
    public function serviceData(): array
    {
        $dados = [
            'name' => $this->string('name')->squish()->value(),
            'category_id' => $this->integer('category_id'),
            'description' => $this->filled('description') ? $this->string('description')->trim()->value() : null,
            'duration_minutes' => $this->integer('duration_minutes'),
        ];

        if ($this->has('price')) {
            $dados['price_cents'] = $this->priceCents();
        }
        foreach (self::DISPLAY_FIELDS as $campo) {
            if ($this->has($campo)) {
                $dados[$campo] = $this->boolean($campo);
            }
        }

        return $dados;
    }

    public function priceCents(): ?int
    {
        try {
            return Money::parse($this->string('price')->value())->cents;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function service(): ?Service
    {
        $s = $this->route('service');

        return $s instanceof Service ? $s : null;
    }
}
