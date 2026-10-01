<?php

namespace App\Modules\Finance\Services;

use App\Modules\Checkout\Models\Attendance;
use App\Modules\Checkout\Models\AttendanceItem;
use App\Modules\Finance\Enums\CommissionRuleType;
use App\Modules\Finance\Enums\CommissionTarget;
use App\Modules\Finance\Models\CommissionRule;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Shared\Support\Money;
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
 */
final class CommissionCalculator
{
    public function __construct(private readonly CommissionRules $rules) {}

    /**
     * @return list<array{item: AttendanceItem, target: CommissionTarget, base: int, rule: ?CommissionRule, amount: int}>
     */
    public function forAttendance(Attendance $attendance, CarbonInterface $at): array
    {
        $pro = $attendance->professional_id;
        if ($pro === null) {
            return [];
        }

        $itens = $attendance->items()->orderBy('id')->get()->values();
        $descontaveis = $itens->filter(fn (AttendanceItem $i) => $i->isDiscountable())->values();
        $partes = PriceBreakdown::shareDiscount((int) $attendance->discount_cents, $descontaveis->map(fn (AttendanceItem $i) => (int) $i->total_cents)->all());
        $parteDe = [];
        foreach ($descontaveis as $n => $i) {
            $parteDe[$i->id] = $partes[$n];
        }

        $linhas = [];
        foreach ($itens as $item) {
            $alvo = $item->isDiscountable() ? CommissionTarget::Service : CommissionTarget::Product;
            $base = max(0, (int) $item->total_cents - ($parteDe[$item->id] ?? 0));
            $regra = $this->rules->resolve($alvo, $pro, $alvo === CommissionTarget::Service ? $item->service_id : null, $at);

            $linhas[] = [
                'item' => $item,
                'target' => $alvo,
                'base' => $base,
                'rule' => $regra,
                'amount' => self::amountFor($regra, $base, $item->quantity),
            ];
        }

        return $linhas;
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
