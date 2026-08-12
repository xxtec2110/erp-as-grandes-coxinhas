<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class DecimalFormatter
{
    public static function format(string $value, int $scale = 2): string
    {
        $decimal = (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        $negative = str_starts_with($integer, '-');
        $digits = $negative ? substr($integer, 1) : $integer;
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits) ?? $digits;

        return ($negative ? '-' : '').$grouped.($scale > 0 ? ','.$fraction : '');
    }
}
