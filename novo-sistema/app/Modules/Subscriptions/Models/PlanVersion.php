<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Catalog\Models\Service;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Versao de um plano: preco, periodicidade e servicos incluidos. Imutavel:
 * so o encerramento (ends_at, current_plan_id) muda. A assinatura aponta a
 * versao contratada, entao mudar o preco nunca reescreve o passado.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $version
 * @property int $price_cents
 * @property string $interval
 * @property string|null $gateway_price_id
 * @property int|null $current_plan_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $reason
 */
class PlanVersion extends Model
{
    public const INTERVALS = ['month' => 'Mensal'];

    protected $table = 'plan_versions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $v): void {
            if ($v->price_cents < 1 || $v->price_cents > 10_000_000) {
                throw DomainRuleViolation::rule('R-PLAN', 'Preço do plano entre R$ 0,01 e R$ 100.000,00.');
            }
            if (! array_key_exists($v->interval, self::INTERVALS)) {
                throw DomainRuleViolation::rule('R-PLAN', 'Periodicidade inválida.');
            }
        });
        static::updating(function (self $v): void {
            if (array_diff(array_keys($v->getDirty()), ['ends_at', 'current_plan_id', 'updated_at']) !== []) {
                throw DomainRuleViolation::rule('R-HIST', 'Versão de plano é histórico: crie uma versão nova.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Versão de plano é histórico.'));
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'plan_version_services')->withTrashed();
    }

    /** @return list<int> */
    public function serviceIds(): array
    {
        $ids = array_map('intval', $this->services()->pluck('services.id')->all());
        sort($ids);

        return $ids;
    }

    public function priceLabel(): string
    {
        return Money::fromCents($this->price_cents)->format().' / '.mb_strtolower(self::INTERVALS[$this->interval] ?? $this->interval);
    }
}
