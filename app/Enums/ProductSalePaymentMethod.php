<?php

namespace App\Enums;

enum ProductSalePaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case Debit = 'debit';
    case Credit = 'credit';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'Pix',
            self::Debit => 'Débito',
            self::Credit => 'Crédito',
        };
    }

    public function requiresCardConfiguration(): bool
    {
        return $this === self::Debit || $this === self::Credit;
    }
}
