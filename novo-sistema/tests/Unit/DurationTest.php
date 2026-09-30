<?php

namespace Tests\Unit;

use App\Modules\Shared\Support\Duration;
use App\Modules\Shared\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Duracao (minutos inteiros) e o formato de campo do dinheiro: as unicas
 * conversoes de exibicao do catalogo.
 */
class DurationTest extends TestCase
{
    public function test_regra_unica_de_duracao(): void
    {
        foreach ([5, 15, 30, 45, 90, 480] as $ok) {
            $this->assertTrue(Duration::isValid($ok), (string) $ok);
        }
        foreach ([0, -5, 3, 7, 485, 1000] as $ruim) {
            $this->assertFalse(Duration::isValid($ruim), (string) $ruim);
        }

        $this->expectException(InvalidArgumentException::class);
        Duration::assertValid(13);
    }

    public function test_formato_de_exibicao(): void
    {
        $this->assertSame('45 min', Duration::format(45));
        $this->assertSame('1 h', Duration::format(60));
        $this->assertSame('1 h 15 min', Duration::format(75));
        $this->assertSame('8 h', Duration::format(480));
    }

    public function test_opcoes_do_seletor_cobrem_toda_a_regra(): void
    {
        $opcoes = Duration::options();
        $this->assertSame(5, array_key_first($opcoes));
        $this->assertSame(480, array_key_last($opcoes));
        $this->assertCount(96, $opcoes);
        $this->assertSame([], array_filter(array_keys($opcoes), fn ($m) => ! Duration::isValid($m)));
    }

    public function test_dinheiro_ida_e_volta_pelo_campo_do_formulario(): void
    {
        foreach ([100, 4500, 4550, 125090, 1000000] as $centavos) {
            $campo = Money::fromCents($centavos)->toInput();
            $this->assertSame($centavos, Money::parse($campo)->cents, $campo);
        }
        $this->assertSame('1250,90', Money::fromCents(125090)->toInput());
    }
}
