<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Media\ImageStore;
use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Support\Duration;
use App\Modules\Shared\Support\Money;
use App\Modules\Shared\Support\UniqueSlug;
use App\Modules\Team\Models\Professional;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Servico do catalogo: a UNICA definicao de nome, preco ATUAL e duracao.
 *
 * - price_cents: preco atual, em centavos. Vale para agendamentos NOVOS; o
 *   valor de cada agendamento e fotografado em appointment_items (historico
 *   imutavel). Mudar o preco aqui nunca altera o passado (precos.md).
 * - duration_minutes: minutos inteiros, multiplo de Duration::STEP_MINUTES.
 * - is_active: oferecido para novos agendamentos. Inativo continua existindo
 *   (historico); nunca e apagado se ja foi usado.
 * - is_public / is_featured: aparece / tem destaque no site (Fase 11).
 *
 * "Agendavel" e "publico" sao decididos SO pelos escopos abaixo.
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property int $duration_minutes
 * @property int $price_cents
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_featured
 * @property string|null $image_path
 * @property int $sort_order
 * @property int $lock_version
 */
#[UseFactory(ServiceFactory::class)]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** Limites do preco atual (centavos): R$ 1,00 a R$ 10.000,00. */
    public const MIN_PRICE_CENTS = 100;

    public const MAX_PRICE_CENTS = 1_000_000;

    protected $table = 'services';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['lock_version'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s): void {
            $s->slug ??= UniqueSlug::for('services', $s->name);
        });

        static::saving(function (self $s): void {
            // Registros antigos (importados) podem ter valores fora da regra
            // atual; a regra vale sempre que o valor e alterado.
            if (($s->isDirty('duration_minutes') || ! $s->exists) && ! Duration::isValid((int) $s->duration_minutes)) {
                throw DomainRuleViolation::rule('R-CAT', 'Duracao do servico deve ser multipla de '.Duration::STEP_MINUTES.' min, entre '.Duration::STEP_MINUTES.' e '.Duration::MAX_MINUTES.'.');
            }
            if (($s->isDirty('price_cents') || ! $s->exists) && ($s->price_cents < self::MIN_PRICE_CENTS || $s->price_cents > self::MAX_PRICE_CENTS)) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Preco do servico fora dos limites.');
            }
        });
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * @return BelongsToMany<Professional, $this>
     */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'professional_service');
    }

    /**
     * Pode ser escolhido num agendamento NOVO: ativo e com categoria ativa
     * (ou sem categoria).
     *
     * @param  Builder<self>  $q
     */
    public function scopeBookable(Builder $q): void
    {
        $q->where('services.is_active', true)
            ->where(fn (Builder $c) => $c->whereNull('services.category_id')
                ->orWhereHas('category', fn (Builder $cat) => $cat->where('is_active', true)));
    }

    /**
     * Aparece no site: agendavel E publico.
     *
     * @param  Builder<self>  $q
     */
    public function scopeShownPublicly(Builder $q): void
    {
        $q->bookable()->where('services.is_public', true);
    }

    /**
     * @param  Builder<self>  $q
     */
    public function scopeOrdered(Builder $q): void
    {
        $q->orderBy('services.sort_order')->orderBy('services.name');
    }

    public function isBookable(): bool
    {
        return $this->is_active && ($this->category_id === null || (bool) $this->category?->is_active);
    }

    public function price(): Money
    {
        return Money::fromCents($this->price_cents);
    }

    public function durationLabel(): string
    {
        return Duration::format($this->duration_minutes);
    }

    public function imageUrl(): ?string
    {
        return ImageStore::url($this->image_path);
    }
}
