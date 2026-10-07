<?php

namespace App\Modules\Finance\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case Other = 'other';
    /** Vale-presente (Fase 8): forma de pagamento; o dinheiro entrou na venda do vale. */
    case GiftCard = 'gift_card';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'Pix',
            self::DebitCard => 'Débito',
            self::CreditCard => 'Crédito',
            self::Other => 'Outro',
            self::GiftCard => 'Vale-presente',
            self::Unknown => 'Não informado',
        };
    }

    /**
     * Formas aceitas no balcao ("nao informado" so existe no legado). Mesma
     * lista no atendimento do painel e no da area do profissional.
     *
     * @return array<string, string>
     */
    public static function counterOptions(): array
    {
        $formas = [];
        foreach (self::cases() as $m) {
            if ($m !== self::Unknown) {
                $formas[$m->value] = $m->label();
            }
        }

        return $formas;
    }
}
