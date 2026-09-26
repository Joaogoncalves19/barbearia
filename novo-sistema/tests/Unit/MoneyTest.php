<?php

namespace Tests\Unit;

use App\Modules\Shared\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int}> */
    public static function valoresDigitados(): array
    {
        return [
            'inteiro' => ['45', 4500],
            'virgula decimal' => ['45,90', 4590],
            'ponto decimal' => ['45.9', 4590],
            'milhar brasileiro' => ['1.234,56', 123456],
            'milhar sem decimal' => ['1.234', 123400],
            'com R$ e espacos' => ['R$ 1.234,56', 123456],
            'negativo' => ['-10,50', -1050],
            'zero' => ['0', 0],
        ];
    }

    #[DataProvider('valoresDigitados')]
    public function test_converte_texto_em_centavos_sem_float(string $texto, int $centavos): void
    {
        $this->assertSame($centavos, Money::parse($texto)->cents);
    }

    public function test_recusa_texto_que_nao_e_valor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('dez reais');
    }

    public function test_soma_subtrai_e_nao_fica_negativo_quando_pedido(): void
    {
        $servico = Money::fromCents(6000);
        $desconto = Money::fromCents(7500);

        $this->assertSame(13500, $servico->add($desconto)->cents);
        $this->assertTrue($servico->subtract($desconto)->isNegative());
        $this->assertTrue($servico->subtract($desconto)->atLeastZero()->isZero());
    }

    public function test_percentual_em_pontos_base_arredonda_meio_centavo_para_cima(): void
    {
        // 12,5% de R$ 45,90 = 5,7375 -> 5,74
        $this->assertSame(574, Money::fromCents(4590)->percentOf(1250)->cents);
        // 10% de R$ 0,05 = 0,005 -> 0,01
        $this->assertSame(1, Money::fromCents(5)->percentOf(1000)->cents);
    }

    public function test_formata_em_reais(): void
    {
        $this->assertSame('R$ 1.234,56', Money::fromCents(123456)->format());
        $this->assertSame('R$ 0,05', Money::fromCents(5)->format());
        $this->assertSame('-R$ 10,00', Money::fromCents(-1000)->format());
    }

    public function test_e_imutavel(): void
    {
        $a = Money::fromCents(100);
        $a->add(Money::fromCents(50));
        $this->assertSame(100, $a->cents);
    }
}
