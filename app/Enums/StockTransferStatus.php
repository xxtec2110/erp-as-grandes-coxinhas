<?php

namespace App\Enums;

enum StockTransferStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::InTransit => 'Em trânsito',
            self::Received => 'Recebida',
            self::Cancelled => 'Cancelada',
        };
    }
}
