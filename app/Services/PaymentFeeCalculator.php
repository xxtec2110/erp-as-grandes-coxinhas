<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PaymentFeeCalculator
{
    /** @return array{fee_amount:string,net_amount:string} */
    public function calculate(string $grossAmount, string $percentage, string $fixedFee): array
    {
        $gross = BigDecimal::of($grossAmount);
        $percentFee = $gross->multipliedBy($percentage)->dividedBy('100', 8, RoundingMode::HalfUp);
        $fee = $percentFee->plus($fixedFee)->toScale(2, RoundingMode::HalfUp);

        return ['fee_amount' => (string) $fee, 'net_amount' => (string) $gross->minus($fee)->toScale(2, RoundingMode::HalfUp)];
    }
}
