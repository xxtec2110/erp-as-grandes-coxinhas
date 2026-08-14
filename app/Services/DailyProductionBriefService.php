<?php

namespace App\Services;

use App\Models\ProductionUserPolicy;
use Carbon\CarbonImmutable;

class DailyProductionBriefService
{
    public function __construct(private ProductionRequirementService $requirements, private StockPositionService $positions) {}

    public function build(ProductionUserPolicy $policy, ?CarbonImmutable $date = null): string
    {
        $date ??= CarbonImmutable::today(config('app.timezone'));
        $stock = $this->positions->forLocation($policy->location);
        $required = $this->requirements->forLocation($policy->location);
        $lines = ['🏭 PRODUÇÃO DE HOJE', '📅 '.$date->format('d/m/Y'), "🏪 {$policy->location->name}", '', '📦 ESTOQUE ATUAL'];
        foreach ($stock as $r) {
            $lines[] = "{$r['product']->name}: {$r['balance']}";
        }$lines[] = '';
        $lines[] = '🎯 PRODUZIR';
        foreach ($required as $r) {
            if ((string) $r['requirement'] !== '0.000000') {
                $lines[] = "{$r['product']->name}: {$r['requirement']}";
            }
        }

return implode("\n", $lines);
    }
}
