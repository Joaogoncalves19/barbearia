<?php

namespace App\Modules\Finance\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceDiscount;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Scheduling\Enums\AdjustmentKind;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Shared\Support\Money;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionBenefits;
use Carbon\CarbonInterface;

/**
 * O UNICO calculo de comissao (comissoes.md §3). Le os valores CONGELADOS do
 * atendimento concluido (itens e desconto total) e as regras em vigor no
 * instante da conclusao; nao grava nada (quem grava e o ProfessionalLedger).
 *
 * Base de cada item:
 * - servico/combo: valor do item menos a sua parte do desconto do
 *   atendimento (rateio pelo maior resto, PriceBreakdown::shareDiscount);
 *   comissao sobre o que foi efetivamente cobrado, como no sistema atual;
 * - produto: valor do item (desconto nao incide em produto).
 *
 * Valor: percentual = base x taxa, meio centavo para cima (Money::percentOf);
 * fixo = valor x quantidade; sem regra ou "sem comissao" = 0 (o lancamento
 * existe mesmo assim, com o motivo, para o historico dizer por que deu zero).
 * Gorjeta NAO entra aqui: e do profissional, sem regra (repasses.md §3).
 *
 * Assinatura (Fase 9, D-46, como no sistema antigo): servico COBERTO pelo
 * beneficio da assinatura tem comissao sobre o PRECO DE TABELA (o valor do
 * item), pela regra de assinatura do profissional (percentual, valor fixo por
 * atendimento ou sem comissao); sem regra de assinatura, vale a regra normal
 * do servico sobre o preco de tabela. O desconto da assinatura e atribuido
 * so aos servicos cobertos: os outros itens tem comissao sobre o cobrado.
 */
final class CommissionCalculator
{
    public function __construct(
        private readonly CommissionRules $rules,
        private readonly SubscriptionBenefits $benefits,
    ) {}

    /**
     * @return list<array{item: AttendanceItem, target: CommissionTarget, base: int, rule: ?CommissionRule, amount: int, note: ?string}>
     */
    public function forAttendance(Attendance $attendance, CarbonInterface $at): array
    {
        $pro = $attendance->professional_id;
        if ($pro === null) {
            return [];
        }

        $itens = $attendance->items()->orderBy('id')->get()->values();
        $cobertos = $this->coveredItemIds($attendance, $itens->all());
        $descontaveis = $itens->filter(fn (AttendanceItem $i) => $i->isDiscountable())->values();
        $parteDe = [];
        if ($cobertos !== null) {
            // Desconto da assinatura = soma dos cobertos: cada coberto leva o proprio valor.
            foreach ($descontaveis as $i) {
                $parteDe[$i->id] = in_array($i->id, $cobertos, true) ? (int) $i->total_cents : 0;
            }
        } else {
            $partes = PriceBreakdown::shareDiscount((int) $attendance->discount_cents, $descontaveis->map(fn (AttendanceItem $i) => (int) $i->total_cents)->all());
            foreach ($descontaveis as $n => $i) {
                $parteDe[$i->id] = $partes[$n];
            }
        }

        $linhas = [];
        $fixoLancado = false;
        foreach ($itens as $item) {
            $alvo = $item->isDiscountable() ? CommissionTarget::Service : CommissionTarget::Product;
            if ($cobertos !== null && in_array($item->id, $cobertos, true)) {
                // Coberto pela assinatura: base = preco de tabela do item.
                $base = max(0, (int) $item->total_cents);
                $regraAssinatura = $this->rules->resolve(CommissionTarget::Subscription, $pro, null, $at);
                $nota = 'Serviço coberto pela assinatura: comissão sobre o preço de tabela';
                if ($regraAssinatura === null) {
                    $regra = $this->rules->resolve(CommissionTarget::Service, $pro, $item->service_id, $at);
                    $valor = self::amountFor($regra, $base, $item->quantity);
                } elseif ($regraAssinatura->type === CommissionRuleType::Fixed) {
                    $regra = $regraAssinatura;
                    $valor = $fixoLancado ? 0 : (int) $regraAssinatura->amount_cents; // um valor fixo por atendimento
                    $nota .= $fixoLancado ? ' (valor fixo já lançado neste atendimento)' : '';
                    $fixoLancado = true;
                } else {
                    $regra = $regraAssinatura;
                    $valor = self::amountFor($regra, $base, $item->quantity);
                }
                $linhas[] = ['item' => $item, 'target' => CommissionTarget::Subscription, 'base' => $base, 'rule' => $regra, 'amount' => $valor, 'note' => $nota];

                continue;
            }
            $base = max(0, (int) $item->total_cents - ($parteDe[$item->id] ?? 0));
            $regra = $this->rules->resolve($alvo, $pro, $alvo === CommissionTarget::Service ? $item->service_id : null, $at);

            $linhas[] = [
                'item' => $item,
                'target' => $alvo,
                'base' => $base,
                'rule' => $regra,
                'amount' => self::amountFor($regra, $base, $item->quantity),
                'note' => null,
            ];
        }

        return $linhas;
    }

    /**
     * Itens cobertos pelo beneficio da assinatura aplicado no atendimento, ou
     * nulo se o desconto do atendimento nao e de assinatura.
     *
     * @param  list<AttendanceItem>  $items
     * @return list<int>|null
     */
    private function coveredItemIds(Attendance $attendance, array $items): ?array
    {
        $desconto = $attendance->discounts()->get()->first(fn (AttendanceDiscount $d) => $d->kind === AdjustmentKind::Subscription && $d->subscription_id !== null);
        $assinatura = $desconto !== null ? Subscription::query()->find($desconto->subscription_id) : null;
        if ($assinatura === null) {
            return null;
        }
        $servicos = $this->benefits->coveredServiceIds($assinatura);

        return array_values(array_map(fn (AttendanceItem $i) => $i->id, array_filter($items,
            fn (AttendanceItem $i) => $i->isDiscountable() && $i->service_id !== null && in_array((int) $i->service_id, $servicos, true))));
    }

    public static function amountFor(?CommissionRule $rule, int $baseCents, int $quantity): int
    {
        return match ($rule?->type) {
            CommissionRuleType::Percent => Money::fromCents($baseCents)->percentOf((int) $rule->rate_bp)->cents,
            CommissionRuleType::Fixed => (int) $rule->amount_cents * max(1, $quantity),
            default => 0,
        };
    }
}
