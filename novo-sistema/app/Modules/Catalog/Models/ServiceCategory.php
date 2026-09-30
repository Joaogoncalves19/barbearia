<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Support\UniqueSlug;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Categoria do catalogo ("Cabelo", "Barba"). Organiza a exibicao; categoria
 * INATIVA tira seus servicos de novos agendamentos (Service::scopeBookable)
 * sem mexer em nada do historico. So pode ser excluida se estiver vazia.
 *
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property int $lock_version
 */
#[UseFactory(ServiceCategoryFactory::class)]
class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'service_categories';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected array $auditExclude = ['lock_version'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c): void {
            $c->slug ??= UniqueSlug::for('service_categories', $c->name);
        });
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $q
     */
    public function scopeOrdered(Builder $q): void
    {
        $q->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<self>  $q
     */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }
}
