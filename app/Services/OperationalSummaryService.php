<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OperationalSummaryService
{
    /** @return array<string, array<string, string>> */
    public function summarize(Location $location, string $startDate, string $endDate): array
    {
        $rows = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereBelongsTo($location)
            ->whereBetween('operation_date', [$startDate, $endDate])
            ->selectRaw('stock_movements.type, products.stock_unit, SUM(stock_movements.quantity_delta) AS total')
            ->groupBy('stock_movements.type', 'products.stock_unit')
            ->get();
        $mapping = [
            StockMovementType::Production->value => 'production',
            StockMovementType::Entry->value => 'entries',
            StockMovementType::OpeningBalance->value => 'entries',
            StockMovementType::Outbound->value => 'outbound',
            StockMovementType::Sale->value => 'outbound',
            StockMovementType::TransferOut->value => 'transfers',
            StockMovementType::TransferIn->value => 'receipts',
            StockMovementType::Loss->value => 'losses',
            StockMovementType::Adjustment->value => 'adjustments',
            StockMovementType::Reversal->value => 'adjustments',
        ];
        $summary = array_fill_keys(array_unique(array_values($mapping)), []);

        foreach ($rows as $row) {
            $type = $row->type instanceof StockMovementType ? $row->type->value : $row->type;
            $metric = $mapping[$type] ?? null;

            if ($metric === null) {
                continue;
            }

            $current = BigDecimal::of($summary[$metric][$row->stock_unit] ?? 0);
            $summary[$metric][$row->stock_unit] = (string) $current
                ->plus((string) $row->total)
                ->toScale(6, RoundingMode::HalfUp);
        }

        foreach ($summary as $metric => $units) {
            foreach ($units as $unit => $value) {
                $summary[$metric][$unit] = (string) BigDecimal::of($value)->abs()->toScale(6);
            }
        }

        return $summary;
    }
}
