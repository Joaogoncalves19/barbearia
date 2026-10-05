<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Shared\Models\Concerns\Auditable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plano de assinatura (planos.md). O nome, a descricao e "ativo para novas
 * adesoes" ficam aqui; preco, periodicidade e servicos incluidos ficam nas
 * VERSOES (PlanVersion): mudar cria uma versao nova e quem ja assina continua
 * na versao contratada.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by_user_id
 */
#[UseFactory(PlanFactory::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'plans';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->orderBy('version');
    }

    /**
     * @return HasOne<PlanVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class, 'current_plan_id');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
