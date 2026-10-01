<?php

namespace App\Modules\Shared\Pricing;

use App\Modules\Loyalty\Enums\DiscountType;
use App\Modules\Shared\Support\Money;
use InvalidArgumentException;

/**
 * Uma regra de desconto: percentual (em pontos-base, 10000 = 100%) ou valor
 * fixo (centavos). E a UNICA forma de calcular quanto um desconto vale
 * (pagamentos.md, "Descontos"): agendamento, atendimento e as promocoes da
 * Fase 8 usam esta classe.
 *
 * Arredondamento (regra unica): percentual = base x pontos-base / 10000,
 * meio centavo para cima (Money::percentOf). Um desconto nunca passa da base
 * nem fica negativo.
 */
final class Discount
{
    private function __construct(
        public readonly DiscountType $type,
        public readonly int $value,
    ) {}

    public static function percent(int $basisPoints): self
    {
        if ($basisPoints < 1 || $basisPoints > 10000) {
            throw new InvalidArgumentException('Percentual de desconto deve ficar entre 0,01% e 100%.');
        }

        return new self(DiscountType::Percent, $basisPoints);
    }

    public static function fixed(int $cents): self
    {
        if ($cents < 1) {
            throw new InvalidArgumentException('Desconto em valor deve ser de ao menos R$ 0,01.');
        }

        return new self(DiscountType::Fixed, $cents);
    }

    public static function of(DiscountType $type, int $value): self
    {
        return $type === DiscountType::Percent ? self::percent($value) : self::fixed($value);
    }

    /** Quanto este desconto vale sobre a base (nunca mais que a base). */
    public function amountOn(Money $base): Money
    {
        if ($base->cents <= 0) {
            return Money::zero();
        }

        $valor = $this->type === DiscountType::Percent
            ? $base->percentOf($this->value)
            : Money::fromCents($this->value);

        return $valor->greaterThan($base) ? $base : $valor;
    }

    /** "10%" ou "R$ 5,00". */
    public function label(): string
    {
        if ($this->type === DiscountType::Fixed) {
            return Money::fromCents($this->value)->format();
        }

        $inteiro = intdiv($this->value, 100);
        $resto = $this->value % 100;

        return $inteiro.($resto > 0 ? ','.rtrim(str_pad((string) $resto, 2, '0', STR_PAD_LEFT), '0') : '').'%';
    }
}
