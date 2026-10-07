<?php

namespace Tests\Unit;

use App\Modules\LegacyImport\Support\LegacyValue as V;
use App\Modules\Shared\Support\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalAndLegacyValueTest extends TestCase
{
    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function decimais(): array
    {
        return [
            'inteiro' => ['45', 4500, false],
            'ponto' => ['45.5', 4550, false],
            'virgula' => ['45,50', 4550, false],
            'milhar br' => ['1.234,56', 123456, false],
            'milhar us' => ['1,234.56', 123456, false],
            'arredonda para cima' => ['33.335', 3334, true],
            'arredonda para baixo' => ['33.334', 3333, true],
            'meio centavo' => ['0.005', 1, true],
            'negativo' => ['-12.3', -1230, false],
            'moeda' => ['R$ 1.234,00', 123400, false],
            'zeros a mais nao contam como arredondamento' => ['10.500', 1050, false],
            // Regressao de float: 0.1 + 0.2 e 1.005 nunca passam por ponto flutuante.
            'float classico' => ['1.005', 101, true],
            'float 0.29' => ['0.29', 29, false],
        ];
    }

    #[DataProvider('decimais')]
    public function test_converte_texto_em_inteiro_sem_float(string $texto, int $esperado, bool $arredondou): void
    {
        $r = Decimal::toScaledInt($texto);
        $this->assertSame($esperado, $r['value']);
        $this->assertSame($arredondou, $r['rounded']);
    }

    public function test_recusa_texto_que_nao_e_numero(): void
    {
        foreach (['', 'abc', 'dez reais', '1.2.3,4,5', '--1'] as $invalido) {
            try {
                Decimal::toScaledInt($invalido);
                $this->fail("Aceitou \"{$invalido}\"");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_dinheiro_legado_sinaliza_formato_que_o_sistema_antigo_lia_diferente(): void
    {
        $this->assertSame(['cents' => 3000, 'rounded' => false, 'divergent' => false], V::money('30'));
        $this->assertSame(['cents' => 3000, 'rounded' => false, 'divergent' => true], V::money('30,00'));
        $this->assertSame(['cents' => null, 'rounded' => false, 'divergent' => false], V::money('gratis'));
        $this->assertSame(['cents' => null, 'rounded' => false, 'divergent' => false], V::money(''));
        // REAL do SQLite chega como texto (ATTR_STRINGIFY_FETCHES).
        $this->assertSame(9990, V::money('99.9')['cents']);
    }

    public function test_dinheiro_com_milhar_gravado_pelo_sistema_antigo(): void
    {
        // Ensaio da Fase 13: o antigo trocava a virgula por ponto ao salvar
        // ("1.500,00" -> "1.500.00") e exibia 1,50. Le-se o que foi digitado.
        $this->assertSame(['cents' => 150000, 'rounded' => false, 'divergent' => true], V::money('1.500.00'));
        $this->assertSame(['cents' => 123456789, 'rounded' => false, 'divergent' => true], V::money('1.234.567.89'));
        $this->assertSame(['cents' => 250050, 'rounded' => false, 'divergent' => true], V::money('2.500.5'));
        // "1.500" sem decimais continua como o antigo lia (1,50): ambiguo, sem adivinhar.
        $this->assertSame(['cents' => 150, 'rounded' => false, 'divergent' => false], V::money('1.500'));
    }

    public function test_percentual_em_pontos_base(): void
    {
        $this->assertSame(4000, V::percentBp('40'));
        $this->assertSame(3750, V::percentBp('37.5'));
        $this->assertSame(1250, V::percentBp('12,5%'));
        $this->assertNull(V::percentBp('abc'));
        $this->assertNull(V::percentBp('150'));
    }

    public function test_datas_e_horas(): void
    {
        $this->assertSame('1990-05-10', V::date('1990-05-10'));
        $this->assertSame('1990-05-10', V::date('10/05/1990'));
        $this->assertNull(V::date('31/02/1990'));
        $this->assertNull(V::date('2026-13-45'));
        $this->assertSame('09:30:00', V::time('9:30'));
        $this->assertNull(V::time('25:00'));

        // Hora local de Sao Paulo (UTC-3) vira UTC.
        $this->assertSame('2026-09-26 12:00:00', V::localDateTime('2026-09-26 09:00', 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-26 03:00:00', V::localDateTime('2026-09-26', 'America/Sao_Paulo')->format('Y-m-d H:i:s'));
        $this->assertNull(V::localDateTime('ontem', 'America/Sao_Paulo'));
    }

    public function test_bcrypt_e_o_unico_hash_aceito(): void
    {
        $this->assertTrue(V::isBcrypt(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])));
        $this->assertFalse(V::isBcrypt(md5('x')));
        $this->assertFalse(V::isBcrypt('senha-em-texto'));
        $this->assertFalse(V::isBcrypt(null));
    }

    public function test_desescape_html_de_um_nivel(): void
    {
        $this->assertSame("Joana D'Avila", V::unescapedText('Joana D&#039;Avila', $mudou));
        $this->assertTrue($mudou);
        $this->assertSame('Barba & Bigode', V::unescapedText('Barba &amp; Bigode'));
        $this->assertSame('Normal', V::unescapedText('Normal', $mudou));
        $this->assertFalse($mudou);
    }
}
