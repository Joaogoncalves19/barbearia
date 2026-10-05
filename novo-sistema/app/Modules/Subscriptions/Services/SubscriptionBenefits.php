<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\PlanVersion;
use App\Modules\Subscriptions\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * O DIREITO ao beneficio da assinatura (beneficios.md), separado do estado.
 *
 * - O direito vem da data PAGA (`ends_on`, inclusive): so um pagamento
 *   confirmado (fatura paga, ou assinatura importada) estende essa data. O
 *   estado sozinho nunca da beneficio: "ativa" sem pagamento confirmado
 *   ainda nao da direito.
 * - Vale em uma data D se D <= ends_on. Assinatura que renova (ativa) tem 1
 *   dia de tolerancia depois do fim (R-28: tempo de o pagamento da renovacao
 *   chegar); em atraso, com cancelamento agendado ou encerrada, nao.
 * - Cancelamento imediato encurta `ends_on` para o dia anterior (servicos);
 *   cancelamento no fim do periodo nao mexe: o beneficio vai ate o fim pago.
 * - "Aguardando pagamento" nunca tem direito.
 *
 * O beneficio em si (decisao do dono, D-44): os servicos incluidos na versao
 * contratada do plano saem de graca, sem limite de uso. Combo e servico comum
 * (D-24): so sai de graca se estiver incluido no plano.
 */
final class SubscriptionBenefits
{
    public const GRACE_DAYS = 1;

    /** @var array<int, list<int>> */
    private array $cobertos = [];

    /**
     * A assinatura que da direito ao beneficio na data local (AAAA-MM-DD).
     */
    public function rightOn(int $customerId, string $localDate): ?Subscription
    {
        $limite = CarbonImmutable::createFromFormat('Y-m-d', $localDate)->subDays(self::GRACE_DAYS)->toDateString();
        $candidatas = Subscription::query()->where('customer_id', $customerId)
            ->whereNotIn('status', [SubscriptionStatus::Pending->value])
            ->whereNotNull('ends_on')->where('ends_on', '>=', $limite)
            ->whereNotNull('plan_version_id')
            ->orderByDesc('ends_on')->orderByDesc('id')->get();

        foreach ($candidatas as $s) {
            if ($this->validOn($s, $localDate)) {
                return $s;
            }
        }

        return null;
    }

    public function validOn(Subscription $s, string $localDate): bool
    {
        if ($s->status === null || $s->status === SubscriptionStatus::Pending || $s->ends_on === null || $s->plan_version_id === null) {
            return false;
        }
        $fim = $s->ends_on->toDateString();
        if ($s->status === SubscriptionStatus::Active) {
            $fim = CarbonImmutable::createFromFormat('Y-m-d', $fim)->addDays(self::GRACE_DAYS)->toDateString();
        }

        return $localDate <= $fim;
    }

    /**
     * Servicos incluidos na versao contratada.
     *
     * @return list<int>
     */
    public function coveredServiceIds(Subscription $s): array
    {
        if ($s->plan_version_id === null) {
            return [];
        }

        return $this->cobertos[$s->plan_version_id] ??= PlanVersion::query()->find($s->plan_version_id)?->serviceIds() ?? [];
    }

    /**
     * Quanto o beneficio vale sobre os itens: a soma dos servicos incluidos.
     *
     * @param  list<array{total: ?int, discountable: bool, unit: ?int, service?: ?int}>  $lines
     */
    public function coveredAmount(Subscription $s, array $lines): int
    {
        $ids = $this->coveredServiceIds($s);
        $soma = 0;
        foreach ($lines as $l) {
            if ($l['discountable'] && ($l['service'] ?? null) !== null && in_array((int) $l['service'], $ids, true)) {
                $soma += max(0, (int) $l['total']);
            }
        }

        return $soma;
    }
}
