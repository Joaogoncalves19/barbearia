<?php

namespace Tests\Unit;

use App\Modules\Shared\Pricing\Discount;
use App\Modules\Shared\Pricing\PriceBreakdown;
use App\Modules\Shared\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A unica regra de desconto e de totais (pagamentos.md, "Descontos" e
 * "Arredondamento").
 */
class DiscountTest extends TestCase
{
    /**
     * @return array<string, array{Discount, int, int}>
     */
    public static function casos(): array
    {
        return [
            '10% de 50,00' => [Discount::percent(1000), 5000, 500],
            '12,5% de 10,00' => [Discount::percent(1250), 1000, 125],
            'meio centavo arredonda para cima: 50% de 10,01' => [Discount::percent(5000), 1001, 501],
            'abaixo de meio centavo arredonda para baixo: 33,33% de 10,00' => [Discount::percent(3333), 1000, 333],
            '100% zera' => [Discount::percent(10000), 4590, 4590],
            'valor fixo' => [Discount::fixed(700), 5000, 700],
            'valor fixo maior que a base fica na base' => [Discount::fixed(9000), 5000, 5000],
            'base zero' => [Discount::percent(1000), 0, 0],
        ];
    }

    #[DataProvider('casos')]
    public function test_valor_do_desconto(Discount $d, int $base, int $esperado): void
    {
        $this->assertSame($esperado, $d->amountOn(Money::fromCents($base))->cents);
    }

    /**
     * @return array<string, array{\Closure(): Discount}>
     */
    public static function invalidos(): array
    {
        return [
            'percentual zero' => [fn () => Discount::percent(0)],
            'percentual acima de 100%' => [fn () => Discount::percent(10001)],
            'percentual negativo' => [fn () => Discount::percent(-100)],
            'valor zero' => [fn () => Discount::fixed(0)],
            'valor negativo' => [fn () => Discount::fixed(-1)],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_valores_invalidos_sao_recusados(\Closure $criar): void
    {
        $this->expectException(InvalidArgumentException::class);
        $criar();
    }

    public function test_desconto_nao_incide_sobre_produto(): void
    {
        $b = PriceBreakdown::calculate([
            ['total' => 5000, 'discountable' => true],
            ['total' => 3500, 'discountable' => false],
        ], [Discount::percent(10000)]);

        $this->assertSame([8500, 5000, 5000, 3500], [$b->subtotal?->cents, $b->discountable?->cents, $b->discount?->cents, $b->total?->cents]);
    }

    public function test_descontos_em_ordem_sobre_o_que_sobrou_da_base(): void
    {
        $b = PriceBreakdown::calculate([['total' => 10000, 'discountable' => true]], [Discount::fixed(2000), Discount::percent(1000)]);

        // 100,00 - 20,00 = 80,00; 10% de 80,00 = 8,00.
        $this->assertSame([[10000, 2000], [8000, 800]], array_map(fn ($a) => [$a['base']->cents, $a['amount']->cents], $b->applied));
        $this->assertSame(7200, $b->total?->cents);
    }

    public function test_desconto_sobre_o_total_e_nao_item_a_item(): void
    {
        // Tres itens de 3,33: 15% item a item daria 0,50 x 3 = 1,50;
        // sobre o total (9,99) da 1,50 tambem, mas 10,01 x 3 mostra a diferenca.
        $itens = array_fill(0, 3, ['total' => 1001, 'discountable' => true]);
        $b = PriceBreakdown::calculate($itens, [Discount::percent(5000)]);

        // 30,03 x 50% = 15,015 -> 15,02 (uma vez so). Item a item seria 5,01 x 3 = 15,03.
        $this->assertSame(1502, $b->discount?->cents);
        $this->assertSame(1501, $b->total?->cents);
    }

    public function test_total_nunca_negativo_com_varios_descontos(): void
    {
        $b = PriceBreakdown::calculate([['total' => 3000, 'discountable' => true]], [Discount::fixed(2000), Discount::fixed(2000)]);

        $this->assertSame([3000, 0], [$b->discount?->cents, $b->total?->cents]);
    }

    public function test_preco_desconhecido_deixa_totais_nulos(): void
    {
        $b = PriceBreakdown::calculate([['total' => null, 'discountable' => true], ['total' => 100, 'discountable' => true]], []);

        $this->assertNull($b->total);
    }

    public function test_rotulo(): void
    {
        $this->assertSame(['10%', '12,5%', '0,01%', 'R$ 7,00'], [
            Discount::percent(1000)->label(), Discount::percent(1250)->label(), Discount::percent(1)->label(), Discount::fixed(700)->label(),
        ]);
    }
}
