<?php

namespace App\Modules\Finance\Models;

use App\Modules\Catalog\Models\Service;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Exceptions\DomainRuleViolation;
use App\Modules\Shared\Models\Concerns\AppendOnly;
use App\Modules\Shared\Support\Money;
use App\Modules\Team\Models\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Regra de comissao VERSIONADA (comissoes.md §2). Nunca e editada: mudar a
 * regra encerra esta (ends_at, current_scope nulo) e cria outra. A comissao
 * ja calculada guarda a regra usada (id + fotografia), entao mudar a regra
 * nao muda o passado.
 *
 * Escopo (do mais especifico ao mais geral, ver CommissionRules::resolve):
 * profissional + servico, servico, profissional, barbearia (os dois nulos).
 * Produto: profissional ou barbearia (sem servico).
 *
 * @property int $id
 * @property CommissionTarget $target
 * @property int|null $professional_id
 * @property int|null $service_id
 * @property CommissionRuleType $type
 * @property int|null $rate_bp
 * @property int|null $amount_cents
 * @property string $scope_key
 * @property string|null $current_scope
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $reason
 * @property int|null $created_by_user_id
 * @property int|null $ended_by_user_id
 */
class CommissionRule extends Model
{
    use AppendOnly;

    protected $table = 'commission_rules';

    protected $guarded = ['id'];

    /**
     * Encerrar a regra e a unica mudanca permitida (uma vez).
     *
     * @var list<string>
     */
    protected array $appendOnlyMutable = ['ends_at', 'current_scope', 'ended_by_user_id'];

    public const MAX_FIXED_CENTS = 100_000_00;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target' => CommissionTarget::class,
            'type' => CommissionRuleType::class,
            'rate_bp' => 'integer',
            'amount_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function scopeKey(CommissionTarget $target, ?int $professionalId, ?int $serviceId): string
    {
        return $target->value.'|p'.($professionalId ?? '*').'|s'.($serviceId ?? '*');
    }

    protected static function booted(): void
    {
        static::saving(function (self $r): void {
            $ok = match ($r->type) {
                CommissionRuleType::Percent => $r->rate_bp !== null && $r->rate_bp >= 0 && $r->rate_bp <= 10000 && $r->amount_cents === null,
                CommissionRuleType::Fixed => $r->amount_cents !== null && $r->amount_cents >= 0 && $r->amount_cents <= self::MAX_FIXED_CENTS && $r->rate_bp === null && $r->target === CommissionTarget::Service,
                CommissionRuleType::None => $r->rate_bp === null && $r->amount_cents === null,
            };
            if (! $ok || ($r->target === CommissionTarget::Product && $r->service_id !== null)) {
                throw DomainRuleViolation::rule('R-COMISSAO', 'Regra de comissao: percentual entre 0% e 100%, valor fixo so para servico, produto sem servico.');
            }
            if ($r->scope_key !== self::scopeKey($r->target, $r->professional_id, $r->service_id)
                || ($r->current_scope !== null && $r->current_scope !== $r->scope_key)) {
                throw DomainRuleViolation::rule('R-COMISSAO', 'Regra de comissao: escopo incoerente.');
            }
            if ($r->exists && $r->getOriginal('ends_at') !== null && $r->isDirty(['ends_at', 'current_scope'])) {
                throw DomainRuleViolation::rule('R-HIST', 'Regra de comissao encerrada nao muda.');
            }
        });
        static::deleting(fn () => throw DomainRuleViolation::rule('R-HIST', 'Regra de comissao e historico.'));
    }

    /**
     * Fotografia gravada junto de cada comissao calculada.
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(): array
    {
        return [
            'regra_id' => $this->id,
            'alvo' => $this->target->value,
            'escopo' => $this->scopeLabel(),
            'tipo' => $this->type->value,
            'percentual_bp' => $this->rate_bp,
            'valor_fixo_cents' => $this->amount_cents,
            'descricao' => $this->describe(),
        ];
    }

    public function describe(): string
    {
        return match ($this->type) {
            CommissionRuleType::Percent => self::percentLabel((int) $this->rate_bp),
            CommissionRuleType::Fixed => Money::fromCents((int) $this->amount_cents)->format().' por unidade',
            CommissionRuleType::None => 'Sem comissão',
        };
    }

    public function scopeLabel(): string
    {
        $pro = $this->professional_id !== null ? ($this->professional->display_name ?? 'profissional') : null;
        $srv = $this->service_id !== null ? ($this->service->name ?? 'serviço') : null;

        return match (true) {
            $pro !== null && $srv !== null => $pro.' · '.$srv,
            $srv !== null => 'Todos · '.$srv,
            $pro !== null => $pro.' · '.($this->target === CommissionTarget::Product ? 'produtos' : 'todos os serviços'),
            default => 'Padrão da barbearia · '.($this->target === CommissionTarget::Product ? 'produtos' : 'serviços'),
        };
    }

    public static function percentLabel(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, ',', '.'), '0'), ',').'%';
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
