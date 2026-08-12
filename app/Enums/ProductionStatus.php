<?php

namespace App\Enums;

enum ProductionStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planejada',
            self::Completed => 'Concluída',
            self::Cancelled => 'Cancelada',
        };
    }
}
