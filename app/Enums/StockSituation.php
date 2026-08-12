<?php

namespace App\Enums;

enum StockSituation: string
{
    case Ok = 'ok';
    case Attention = 'attention';
    case Critical = 'critical';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Attention => 'Atenção',
            self::Critical => 'Crítico',
            self::NotConfigured => 'Sem política',
        };
    }
}
