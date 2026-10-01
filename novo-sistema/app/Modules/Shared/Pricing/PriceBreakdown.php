<?php

namespace App\Modules\Shared\Pricing;

use App\Modules\Shared\Support\Money;

/**
 * O calculo de totais: subtotal, descontos e total. Um lugar so, usado pelo
 * agendamento (AppointmentPricing) e pelo atendimento (AttendancePricing),
 * para que tela, recibo, caixa e relatorio nunca divirjam em um centavo.
 *
 * Regras (pagamentos.md, "Descontos" e "Arredondamento"):
 * - desconto incide so sobre as linhas descontaveis (servicos e combos),
 *   nunca sobre produtos vendidos (regra herdada do sistema atual);
 * - os descontos sao aplicados EM ORDEM, cada um sobre o que sobrou da base
 *   depois dos anteriores; percentual arredonda meio centavo para cima;
 * - o desconto e calculado sobre a base TOTAL, nunca item a item (somar
 *   arredondamentos por item daria centavos de diferenca);
 * - total = subtotal - descontos, nunca negativo;
 * - se alguma linha tem preco desconhecido (legado), os totais ficam nulos.
 */
final class PriceBreakdown
{
    /**
     * @param  list<array{base: Money, amount: Money}>  $applied
     */
    private function __construct(
        public readonly ?Money $subtotal,
        public readonly ?Money $discountable,
        public readonly array $applied,
        public readonly ?Money $discount,
        public readonly ?Money $total,
    ) {}

    /**
     * @param  iterable<array{total: ?int, discountable: bool}>  $lines
     * @param  list<Discount>  $discounts
     */
    public static function calculate(iterable $lines, array $discounts): self
    {
        $subtotal = Money::zero();
        $base = Money::zero();
        foreach ($lines as $line) {
            if ($line['total'] === null) {
                return new self(null, null, [], null, null);
            }
            $subtotal = $subtotal->add(Money::fromCents($line['total']));
            if ($line['discountable']) {
                $base = $base->add(Money::fromCents($line['total']));
            }
        }

        $restante = $base;
        $aplicados = [];
        $desconto = Money::zero();
        foreach ($discounts as $d) {
            $valor = $d->amountOn($restante);
            $aplicados[] = ['base' => $restante, 'amount' => $valor];
            $desconto = $desconto->add($valor);
            $restante = $restante->subtract($valor);
        }

        return new self($subtotal, $base, $aplicados, $desconto, $subtotal->subtract($desconto));
    }

    /**
     * Rateio do desconto entre as linhas descontaveis (comissoes.md §3), pelo
     * metodo do MAIOR RESTO: cada linha recebe a parte proporcional ao seu
     * valor, arredondada para baixo; os centavos que sobram vao, um a um, para
     * as linhas com a maior fracao descartada (empate: a primeira). A soma das
     * partes e exatamente o desconto; nenhuma parte passa do valor da linha.
     *
     * @param  list<int>  $lineTotals  valores das linhas descontaveis, em centavos
     * @return list<int> parte do desconto de cada linha, na mesma ordem
     */
    public static function shareDiscount(int $discountCents, array $lineTotals): array
    {
        $base = array_sum($lineTotals);
        if ($discountCents <= 0 || $base <= 0) {
            return array_fill(0, count($lineTotals), 0);
        }
        $discountCents = min($discountCents, $base);

        $partes = [];
        $restos = [];
        foreach ($lineTotals as $i => $valor) {
            $bruto = $discountCents * max(0, $valor);
            $partes[$i] = intdiv($bruto, $base);
            $restos[$i] = $bruto % $base;
        }

        $sobra = $discountCents - array_sum($partes);
        $ordem = array_keys($restos);
        usort($ordem, fn (int $a, int $b) => $restos[$b] <=> $restos[$a] ?: $a <=> $b);
        foreach ($ordem as $i) {
            if ($sobra <= 0) {
                break;
            }
            $partes[$i]++;
            $sobra--;
        }

        return array_values($partes);
    }
}
