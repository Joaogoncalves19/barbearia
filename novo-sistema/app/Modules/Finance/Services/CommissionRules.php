<?php

namespace App\Modules\Finance\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Exceptions\CommissionRuleViolation;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\System\Services\AuditTrail;
use App\Modules\Team\Models\Professional;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * As UNICAS regras de comissao (comissoes.md §2): qual regra vale para um
 * item, e como uma regra muda. Ninguem le ou grava commission_rules por
 * conta propria.
 *
 * Precedencia (a mais especifica vence):
 *  servico: profissional + servico > servico (todos) > profissional (todos
 *           os servicos) > padrao da barbearia > nenhuma (sem comissao);
 *  produto: profissional > padrao da barbearia > nenhuma.
 * "Sem comissao" (tipo none) e uma regra: vence as mais gerais.
 *
 * Mudar = encerrar a regra em vigor e criar outra, na mesma transacao. A
 * sentinela current_scope (unica) garante uma regra em vigor por escopo.
 * Comissao ja calculada guarda a regra usada: mudar aqui nao muda o passado.
 */
final class CommissionRules
{
    public function current(CommissionTarget $target, ?int $professionalId, ?int $serviceId): ?CommissionRule
    {
        return CommissionRule::query()->where('current_scope', CommissionRule::scopeKey($target, $professionalId, $serviceId))->first();
    }

    /** A regra que vale para o item, no instante (padrao: agora). */
    public function resolve(CommissionTarget $target, int $professionalId, ?int $serviceId, ?CarbonInterface $at = null): ?CommissionRule
    {
        $quando = $at ?? BusinessTime::now();
        $escopos = $target === CommissionTarget::Service
            ? [[$professionalId, $serviceId], [null, $serviceId], [$professionalId, null], [null, null]]
            : [[$professionalId, null], [null, null]];

        $vistos = [];
        foreach ($escopos as [$pro, $srv]) {
            // Item sem servico (combo): os escopos repetem; cada um e consultado uma vez.
            $chave = CommissionRule::scopeKey($target, $pro, $srv);
            if (in_array($chave, $vistos, true)) {
                continue;
            }
            $vistos[] = $chave;

            $regra = CommissionRule::query()->where('scope_key', $chave)
                ->where('starts_at', '<=', $quando)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $quando))
                ->orderByDesc('starts_at')->orderByDesc('id')->first();
            if ($regra !== null) {
                return $regra;
            }
        }

        return null;
    }

    /**
     * Define a regra de um escopo (encerra a anterior, se houver).
     *
     * @throws CommissionRuleViolation
     */
    public function set(CommissionTarget $target, ?Professional $professional, ?Service $service, CommissionRuleType $type, ?int $rateBp, ?int $amountCents, ?string $reason, User $actor): CommissionRule
    {
        if ($target === CommissionTarget::Product) {
            $service = null;
        }
        $rateBp = $type === CommissionRuleType::Percent ? $rateBp : null;
        $amountCents = $type === CommissionRuleType::Fixed ? $amountCents : null;
        $valido = match ($type) {
            CommissionRuleType::Percent => $rateBp !== null && $rateBp >= 0 && $rateBp <= 10000,
            CommissionRuleType::Fixed => $target === CommissionTarget::Service && $amountCents !== null && $amountCents >= 0 && $amountCents <= CommissionRule::MAX_FIXED_CENTS,
            CommissionRuleType::None => true,
        };
        if (! $valido) {
            throw new CommissionRuleViolation('invalid_rule');
        }
        $motivo = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;

        return $this->guard(fn () => DB::transaction(function () use ($target, $professional, $service, $type, $rateBp, $amountCents, $motivo, $actor): CommissionRule {
            $agora = BusinessTime::now();
            $atual = $this->current($target, $professional?->id, $service?->id);
            if ($atual !== null && $atual->type === $type && $atual->rate_bp === $rateBp && $atual->amount_cents === $amountCents) {
                throw new CommissionRuleViolation('no_change');
            }
            $this->end($atual, $actor, $agora);

            $escopo = CommissionRule::scopeKey($target, $professional?->id, $service?->id);
            $nova = CommissionRule::query()->create([
                'target' => $target,
                'professional_id' => $professional?->id,
                'service_id' => $service?->id,
                'type' => $type,
                'rate_bp' => $rateBp,
                'amount_cents' => $amountCents,
                'scope_key' => $escopo,
                'current_scope' => $escopo,
                'starts_at' => $agora,
                'reason' => $motivo,
                'created_by_user_id' => $actor->id,
            ]);

            AuditTrail::record('commission.rule_set', $nova, $actor, 'Regra de comissão definida: '.$nova->scopeLabel().' = '.$nova->describe().'.', [
                'antes' => $atual?->describe(), 'depois' => $nova->describe(), 'escopo' => $nova->scopeLabel(), 'motivo' => $motivo,
            ]);

            return $nova;
        }));
    }

    /**
     * Encerra a regra em vigor do escopo (passa a valer a mais geral).
     *
     * @throws CommissionRuleViolation
     */
    public function clear(CommissionTarget $target, ?Professional $professional, ?Service $service, User $actor): void
    {
        $this->guard(fn () => DB::transaction(function () use ($target, $professional, $service, $actor): void {
            $atual = $this->current($target, $professional?->id, $target === CommissionTarget::Product ? null : $service?->id);
            if ($atual === null) {
                throw new CommissionRuleViolation('no_rule');
            }
            $this->end($atual, $actor, BusinessTime::now());
            AuditTrail::record('commission.rule_cleared', $atual, $actor, 'Regra de comissão encerrada: '.$atual->scopeLabel().'.', [
                'antes' => $atual->describe(), 'escopo' => $atual->scopeLabel(),
            ]);
        }));
    }

    private function end(?CommissionRule $rule, User $actor, CarbonInterface $at): void
    {
        if ($rule === null) {
            return;
        }
        $rule->forceFill(['ends_at' => $at]);
        $rule->current_scope = null;
        $rule->ended_by_user_id = $actor->id;
        $rule->save();
    }

    /**
     * Duas pessoas mudando a mesma regra ao mesmo tempo: a sentinela unica
     * barra a segunda, que recebe uma mensagem clara (nada gravado).
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function guard(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new CommissionRuleViolation('no_change', 'Outra pessoa acabou de alterar esta regra; confira e tente de novo.');
            }
            throw $e;
        }
    }
}
