<?php

namespace App\Http\Controllers;

use App\Services\AuthorizationService;
use App\Services\OperationalSummaryService;
use App\Services\StockPositionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationalReportController extends Controller
{
    public function index(Request $request, OperationalSummaryService $summary, StockPositionService $positions, AuthorizationService $authorization): View
    {
        $locations = $authorization->accessibleLocations($request->user());
        $requestedId = $request->integer('location_id');
        if ($request->has('location_id') && ! $locations->contains('id', $requestedId)) {
            abort(403, 'Você não possui acesso a esta unidade.');
        }
        $location = $locations->firstWhere('id', $requestedId)
            ?? $locations->firstWhere('id', $request->user()->default_location_id)
            ?? $locations->first();
        $startDate = $request->date('start_date')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $endDate = $request->date('end_date')?->toDateString() ?? now()->toDateString();

        return view('reports.operational', [
            'locations' => $locations,
            'location' => $location,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'summary' => $location ? $summary->summarize($location, $startDate, $endDate) : [],
            'positions' => $location ? $positions->forLocation($location) : [],
        ]);
    }
}
