<?php

namespace App\Modules\Shared\Support;

use InvalidArgumentException;

/**
 * Conversao de texto decimal para inteiro escalado SEM passar por float.
 *
 * Usado para dinheiro (escala 2 => centavos) e percentual (escala 2 =>
 * pontos-base). Aceita "45", "45.5", "45,50", "1.234,56", "1234.567" e
 * notacao simples com sinal. Casas alem da escala sao arredondadas meio
 * para cima (half-up) por aritmetica de string, e o chamador e avisado.
 */
final class Decimal
{
    /**
     * @return array{value: int, rounded: bool}
     */
    public static function toScaledInt(string $texto, int $escala = 2): array
    {
        $s = trim(str_replace(['R$', ' ', "\u{00A0}", '%'], '', $texto));
        if ($s === '') {
            throw new InvalidArgumentException('Valor decimal vazio.');
        }

        $negativo = str_starts_with($s, '-');
        $s = ltrim($s, '+-');

        if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s) || preg_match('/^\d+,\d+$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s); // formato brasileiro
        } elseif (preg_match('/^\d{1,3}(,\d{3})+\.\d+$/', $s)) {
            $s = str_replace(',', '', $s); // 1,234.56
        } elseif (preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $s)) {
            $s = str_replace('.', '', $s); // 1.234.567 (milhar sem decimais)
        }

        if (! preg_match('/^(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[1] === '' && ($m[2] ?? '') === '')) {
            throw new InvalidArgumentException("Valor decimal invalido: \"{$texto}\"");
        }

        $inteiro = ltrim($m[1], '0');
        $fracao = $m[2] ?? '';
        $rounded = strlen(rtrim(substr($fracao, $escala), '0')) > 0;
        $arredonda = strlen($fracao) > $escala && (int) $fracao[$escala] >= 5;
        $fracao = substr(str_pad($fracao, $escala, '0'), 0, $escala);

        $digits = ($inteiro === '' ? '0' : $inteiro).$fracao;
        if (strlen($digits) > 15) {
            throw new InvalidArgumentException("Valor decimal fora do limite: \"{$texto}\"");
        }

        $value = (int) $digits + ($arredonda ? 1 : 0);

        return ['value' => $negativo ? -$value : $value, 'rounded' => $rounded];
    }
}
