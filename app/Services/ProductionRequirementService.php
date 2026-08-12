<?php

namespace App\Services;

use App\Enums\ProductionStatus;
use App\Enums\StockTransferStatus;
use App\Models\Location;
use App\Models\ProductionRecord;
use App\Models\StockTransferItem;
use Brick\Math\BigDecimal;

class ProductionRequirementService
{
    public function __construct(private StockPositionService $positions) {}

    /** @return array<int, array<string, mixed>> */
    public function forLocation(Location $location): array
    {
        $requirements = array_map(function (array $row) use ($location): array {
            $plannedProduction = ProductionRecord::query()
                ->where('location_id', $location->id)
                ->where('product_id', $row['product']->id)
                ->where('status', ProductionStatus::Planned)
                ->sum('planned_quantity');
            $incoming = StockTransferItem::query()
                ->where('product_id', $row['product']->id)
                ->whereHas('transfer', fn ($query) => $query
                    ->where('destination_location_id', $location->id)
                    ->where('status', StockTransferStatus::InTransit))
                ->sum('quantity_sent');
            $pendingOutbound = StockTransferItem::query()
                ->where('product_id', $row['product']->id)
                ->whereHas('transfer', fn ($query) => $query
                    ->where('source_location_id', $location->id)
                    ->where('status', StockTransferStatus::Pending))
                ->sum('quantity_sent');
            $covered = BigDecimal::of((string) $plannedProduction)->plus((string) $incoming);
            $requirement = BigDecimal::of($row['target'])
                ->minus($row['balance'])
                ->minus($covered)
                ->plus((string) $pendingOutbound);

            if ($requirement->isNegative()) {
                $requirement = BigDecimal::zero();
            }

            $row['planned_production'] = (string) BigDecimal::of((string) $plannedProduction)->toScale(6);
            $row['incoming_transfers'] = (string) BigDecimal::of((string) $incoming)->toScale(6);
            $row['pending_outbound'] = (string) BigDecimal::of((string) $pendingOutbound)->toScale(6);
            $row['requirement'] = (string) $requirement->toScale(6);

            return $row;
        }, array_values(array_filter(
            $this->positions->forLocation($location),
            fn (array $row): bool => $row['policy']?->active === true,
        )));

        usort($requirements, function (array $left, array $right): int {
            $priority = ($right['policy']->production_priority ?? 0) <=> ($left['policy']->production_priority ?? 0);

            return $priority !== 0
                ? $priority
                : BigDecimal::of($right['requirement'])->compareTo(BigDecimal::of($left['requirement']));
        });

        return $requirements;
    }
}
