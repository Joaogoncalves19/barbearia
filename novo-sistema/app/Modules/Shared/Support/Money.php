<?php

namespace App\Modules\Shared\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Valor monetario em CENTAVOS inteiros.
 *
 * Existe porque o sistema antigo guardava dinheiro como texto e recalculava
 * valores com float. Aqui nao ha float em lugar nenhum: soma, subtracao e
 * percentual trabalham em inteiros, com arredondamento explicito.
 *
 * E o tipo que as colunas `*_cents` do banco vao representar a partir da
 * Fase 2 (ver "Principio de dados historicos" em arquitetura-nova.md).
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(public readonly int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /** Valor para campo de formulario ("1250,00"): o inverso exato de parse(). */
    public function toInput(): string
    {
        $abs = abs($this->cents);
        $texto = intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $this->cents < 0 ? '-'.$texto : $texto;
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converte texto digitado ("45", "45,90", "1.234,56", "45.9") em centavos,
     * sem passar por float. Recusa qualquer coisa que nao seja um valor.
     */
    public static function parse(string $valor): self
    {
        $valor = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $valor));
        $negativo = str_starts_with($valor, '-');
        $valor = ltrim($valor, '-');

        if (preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $valor)) {
            // Formato brasileiro: ponto como milhar, virgula como decimal.
            [$inteiro, $decimal] = explode(',', str_replace('.', '', $valor));
        } elseif (preg_match('/^\d+(\.\d{1,2})?$/', $valor)) {
            [$inteiro, $decimal] = array_pad(explode('.', $valor), 2, '0');
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $valor)) {
            [$inteiro, $decimal] = [str_replace('.', '', $valor), '0'];
        } else {
            throw new InvalidArgumentException("Valor monetario invalido: \"{$valor}\"");
        }

        $cents = ((int) $inteiro * 100) + (int) str_pad($decimal, 2, '0');

        return new self($negativo ? -$cents : $cents);
    }

    /** Como parse(), mas devolve null para texto que nao e um valor (entrada de formulario). */
    public static function tryParse(?string $valor): ?self
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return self::parse($valor);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function add(self $outro): self
    {
        return new self($this->cents + $outro->cents);
    }

    public function subtract(self $outro): self
    {
        return new self($this->cents - $outro->cents);
    }

    /**
     * Percentual em pontos-base (1% = 100) para nao usar float:
     * 12,5% => percentOf(1250). Arredonda meio centavo para cima.
     */
    public function percentOf(int $basisPoints): self
    {
        return new self(intdiv($this->cents * $basisPoints + ($this->cents >= 0 ? 5000 : -5000), 10000));
    }

    /** Nunca abaixo de zero (ex.: desconto maior que o valor). */
    public function atLeastZero(): self
    {
        return $this->cents < 0 ? self::zero() : $this;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $outro): bool
    {
        return $this->cents === $outro->cents;
    }

    public function greaterThan(self $outro): bool
    {
        return $this->cents > $outro->cents;
    }

    /** "R$ 1.234,56" */
    public function format(): string
    {
        $abs = abs($this->cents);
        $texto = 'R$ '.number_format(intdiv($abs, 100), 0, ',', '.').','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $this->cents < 0 ? '-'.$texto : $texto;
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
