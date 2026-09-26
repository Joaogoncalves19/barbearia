<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(ServiceFactory::class)]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'services';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
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

    protected static function booted(): void
    {
        static::saving(function (self $s): void {
            if ($s->duration_minutes <= 0) {
                throw DomainRuleViolation::rule('R-CAT', 'Duracao do servico deve ser positiva.');
            }
            if ($s->price_cents < 0) {
                throw DomainRuleViolation::rule('R-DINHEIRO', 'Preco nao pode ser negativo.');
            }
        });
    }

    public function price(): Money
    {
        return Money::fromCents($this->price_cents);
    }
}
