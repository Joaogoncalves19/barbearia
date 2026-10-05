<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Support\BusinessTime;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Exceptions\SubscriptionRuleViolation;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\PlanVersion;
use App\Modules\System\Services\AuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Planos e versoes (planos.md). So o proprietario configura (plans.manage).
 * Mudar preco, periodicidade ou servicos incluidos cria uma VERSAO nova; quem
 * ja assina continua na versao contratada (o Stripe tambem: o preco de cada
 * assinatura e fixado na adesao). Nada e apagado.
 */
final class Plans
{
    /**
     * @param  list<int>  $serviceIds
     *
     * @throws SubscriptionRuleViolation
     */
    public function create(string $name, ?string $description, int $priceCents, array $serviceIds, User $actor): Plan
    {
        $nome = trim($name);
        $servicos = $this->services($serviceIds);
        if ($nome === '' || $priceCents < 1 || $servicos === []) {
            throw new SubscriptionRuleViolation('invalid_plan');
        }

        return DB::transaction(function () use ($nome, $description, $priceCents, $servicos, $actor): Plan {
            $plano = Plan::query()->create([
                'name' => mb_substr($nome, 0, 255), 'description' => $this->clean($description), 'is_active' => true, 'created_by_user_id' => $actor->id,
            ]);
            $v = $this->version($plano, 1, $priceCents, $servicos, 'Plano criado', $actor);
            AuditTrail::record('plan.created', $plano, $actor, 'Plano '.$plano->name.' criado: '.$v->priceLabel().'.', [
                'preco_cents' => $priceCents, 'servicos' => implode(',', $servicos),
            ]);

            return $plano;
        });
    }

    /**
     * Nova versao (preco e/ou servicos). A anterior fica como historico.
     *
     * @param  list<int>  $serviceIds
     *
     * @throws SubscriptionRuleViolation
     */
    public function newVersion(Plan $plan, int $priceCents, array $serviceIds, string $reason, User $actor): PlanVersion
    {
        $servicos = $this->services($serviceIds);
        $motivo = trim($reason);
        if ($priceCents < 1 || $servicos === []) {
            throw new SubscriptionRuleViolation('invalid_plan');
        }
        if (mb_strlen($motivo) < 3) {
            throw new SubscriptionRuleViolation('reason_required');
        }

        return DB::transaction(function () use ($plan, $priceCents, $servicos, $motivo, $actor): PlanVersion {
            $atual = PlanVersion::query()->where('current_plan_id', $plan->id)->first();
            if ($atual !== null && $atual->price_cents === $priceCents && $atual->serviceIds() == $servicos) {
                throw new SubscriptionRuleViolation('no_change');
            }
            $atual?->forceFill(['current_plan_id' => null, 'ends_at' => BusinessTime::now()])->save();
            $nova = $this->version($plan, ($atual->version ?? 0) + 1, $priceCents, $servicos, $motivo, $actor);
            AuditTrail::record('plan.versioned', $plan, $actor, 'Plano '.$plan->name.': nova versão '.$nova->version.' ('.$nova->priceLabel().').', [
                'preco_antes_cents' => $atual?->price_cents, 'servicos_antes' => $atual !== null ? implode(',', $atual->serviceIds()) : null,
                'preco_depois_cents' => $priceCents, 'servicos_depois' => implode(',', $servicos), 'motivo' => $motivo,
            ]);

            return $nova;
        });
    }

    public function setActive(Plan $plan, bool $active, User $actor): Plan
    {
        $plan->update(['is_active' => $active]);
        AuditTrail::record($active ? 'plan.activated' : 'plan.deactivated', $plan, $actor,
            'Plano '.$plan->name.($active ? ' aberto para novas adesões.' : ' fechado para novas adesões (quem assina continua).'));

        return $plan;
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function version(Plan $plan, int $number, int $priceCents, array $serviceIds, string $reason, User $actor): PlanVersion
    {
        $v = PlanVersion::query()->create([
            'plan_id' => $plan->id, 'version' => $number, 'price_cents' => $priceCents, 'interval' => 'month',
            'current_plan_id' => $plan->id, 'starts_at' => BusinessTime::now(), 'reason' => mb_substr($reason, 0, 255), 'created_by_user_id' => $actor->id,
        ]);
        $v->services()->sync($serviceIds);

        return $v;
    }

    /**
     * Servicos validos (existentes), ordenados e sem repeticao.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function services(array $ids): array
    {
        $validos = Service::query()->whereIn('id', array_map('intval', $ids))->pluck('id')->map(fn ($i) => (int) $i)->all();
        sort($validos);

        return array_values(array_unique($validos));
    }

    private function clean(?string $text): ?string
    {
        $t = trim((string) $text);

        return $t === '' ? null : mb_substr($t, 0, 255);
    }

    public static function money(int $cents): string
    {
        return Money::fromCents($cents)->format();
    }
}
