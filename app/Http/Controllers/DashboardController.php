<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Models\Location;
use App\Services\AuthorizationService;
use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, AuthorizationService $authorization, DashboardService $dashboard): View
    {
        $user = $request->user();
        $locations = $authorization->accessibleLocations($user)->where('type', Location::TYPE_STORE)->values();
        $requestedId = $request->validated('location_id');
        if ($requestedId !== null && ! $locations->contains('id', (int) $requestedId)) {
            abort(403, 'Você não possui acesso a esta unidade.');
        }
        $location = $requestedId !== null
            ? $locations->firstWhere('id', (int) $requestedId)
            : ($locations->firstWhere('id', $user->default_location_id) ?? $locations->first());
        [$start, $end, $periodLabel] = $request->period();

        return view('dashboard', [
            'locations' => $locations,
            'location' => $location,
            'startDate' => $start,
            'endDate' => $end,
            'periodLabel' => $periodLabel,
            'widgets' => $location === null ? [] : $dashboard->widgets($user, $location, $start, $end),
        ]);
    }
}
