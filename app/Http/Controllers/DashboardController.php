<?php

namespace App\Http\Controllers;

use App\Models\Payable;
use App\Models\ProductionOrder;
use App\Models\StockTransfer;
use App\Services\AuthorizationService;
use App\Services\OperationalSummaryService;
use App\Services\StockPositionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AuthorizationService $authorization, OperationalSummaryService $summary, StockPositionService $stock): View
    {
        $locations = $authorization->accessibleLocations($request->user());
        $location = $locations->firstWhere('id', $request->integer('location_id')) ?? $locations->firstWhere('id', $request->user()->default_location_id) ?? $locations->first();
        [$start, $end] = $this->period($request);
        $locationIds = $locations->pluck('id');

        return view('dashboard', [
            'locations' => $locations, 'location' => $location, 'startDate' => $start, 'endDate' => $end,
            'summary' => $location ? $summary->summarize($location, $start, $end) : [],
            'positions' => $location ? $stock->forLocation($location) : [],
            'inTransit' => StockTransfer::query()->where('status', 'in_transit')->where(fn ($query) => $query->whereIn('source_location_id', $locationIds)->orWhereIn('destination_location_id', $locationIds))->count(),
            'openPayables' => $authorization->allows($request->user(), 'dashboard.financial.view') ? Payable::query()->whereIn('location_id', $locationIds)->whereNotIn('status', ['paid', 'cancelled'])->sum('expected_amount') : null,
            'plannedOrders' => $location ? ProductionOrder::query()->where('location_id', $location->id)->where('status', 'planned')->count() : 0,
            'completedOrders' => $location ? ProductionOrder::query()->where('location_id', $location->id)->where('status', 'completed')->whereBetween('production_date', [$start, $end])->count() : 0,
        ]);
    }

    private function period(Request $request): array
    {
        if ($request->input('period') === 'custom') {
            return [$request->date('start_date')?->toDateString() ?? now()->toDateString(), $request->date('end_date')?->toDateString() ?? now()->toDateString()];
        }

        return match ($request->input('period', 'today')) {
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'fortnight' => [now()->subDays(14)->toDateString(), now()->toDateString()],
            'month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            default => [now()->toDateString(), now()->toDateString()],
        };
    }
}
