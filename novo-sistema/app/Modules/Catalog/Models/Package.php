<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Support\Money;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Combo de servicos. Duracao = soma das duracoes dos servicos (regra do
 * sistema atual).
 */
#[UseFactory(PackageFactory::class)]
class Package extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'packages';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    public function durationMinutes(): int
    {
        return (int) $this->items()->join('services', 'services.id', '=', 'package_items.service_id')
            ->sum(DB::raw('services.duration_minutes * package_items.quantity'));
    }

    public function price(): Money
    {
        return Money::fromCents($this->price_cents);
    }
}
