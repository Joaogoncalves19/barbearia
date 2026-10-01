<?php

namespace App\Modules\Receipts\Enums;

/** Comprovantes com impressao e envio por e-mail (comprovantes.md; pedido do dono na Fase 8). */
enum ReceiptType: string
{
    case Attendance = 'attendance';
    case Payout = 'payout';
    case GiftCard = 'gift_card';
    case CashSession = 'cash_session';

    public function label(): string
    {
        return match ($this) {
            self::Attendance => 'Comprovante de atendimento',
            self::Payout => 'Recibo de repasse',
            self::GiftCard => 'Vale-presente',
            self::CashSession => 'Fechamento de caixa',
        };
    }

    public function view(): string
    {
        return 'receipts.'.str_replace('_', '-', $this->value);
    }
}
