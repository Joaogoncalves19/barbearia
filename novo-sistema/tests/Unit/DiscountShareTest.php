<?php

namespace Tests\Unit;

use App\Modules\Shared\Pricing\PriceBreakdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rateio do desconto entre os servicos (maior resto): base da comissao
 * por item (comissoes.md §3).
 */
class DiscountShareTest extends TestCase
{
    /**
     * @return iterable<string, array{int, list<int>, list<int>}>
     */
    public static function casos(): iterable
    {
        yield 'proporcional exato' => [800, [5000, 3000], [500, 300]];
        yield 'centavo para a maior fração' => [1001, [5000, 3000], [626, 375]];
        yield 'três iguais, sobra vai para o primeiro' => [100, [1000, 1000, 1000], [34, 33, 33]];
        yield 'empate de fração: o primeiro' => [1, [500, 500], [1, 0]];
        yield 'linha de valor zero não recebe' => [300, [0, 3000], [0, 300]];
        yield 'sem desconto' => [0, [5000, 3000], [0, 0]];
        yield 'desconto maior que a base é limitado' => [9999, [100, 200], [100, 200]];
        yield 'uma linha só' => [123, [4567], [123]];
        yield 'nenhuma linha' => [500, [], []];
    }

    /**
     * @param  list<int>  $linhas
     * @param  list<int>  $esperado
     */
    #[DataProvider('casos')]
    public function test_rateio(int $desconto, array $linhas, array $esperado): void
    {
        $partes = PriceBreakdown::shareDiscount($desconto, $linhas);

        $this->assertSame($esperado, $partes);
        $this->assertSame(min($desconto, array_sum($linhas)), array_sum($partes), 'a soma é o desconto');
        foreach ($partes as $i => $p) {
            $this->assertLessThanOrEqual($linhas[$i], $p, 'nenhuma parte passa do valor da linha');
        }
    }

    public function test_soma_sempre_fecha_em_muitos_casos(): void
    {
        mt_srand(7);
        for ($n = 0; $n < 500; $n++) {
            $linhas = array_map(fn () => mt_rand(0, 20000), range(1, mt_rand(1, 6)));
            $desconto = mt_rand(0, array_sum($linhas));
            $partes = PriceBreakdown::shareDiscount($desconto, $linhas);
            $this->assertSame($desconto, array_sum($partes));
            foreach ($partes as $i => $p) {
                $this->assertTrue($p >= 0 && $p <= $linhas[$i]);
            }
        }
    }
}
