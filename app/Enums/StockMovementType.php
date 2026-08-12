<?php

namespace App\Enums;

enum StockMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Entry = 'entry';
    case Production = 'production';
    case Outbound = 'outbound';
    case Sale = 'sale';
    case Loss = 'loss';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Saldo inicial',
            self::Entry => 'Entrada',
            self::Production => 'Produção',
            self::Outbound => 'Saída',
            self::Sale => 'Venda',
            self::Loss => 'Perda',
            self::TransferOut => 'Transferência enviada',
            self::TransferIn => 'Transferência recebida',
            self::Adjustment => 'Ajuste',
            self::Reversal => 'Estorno',
        };
    }
}
