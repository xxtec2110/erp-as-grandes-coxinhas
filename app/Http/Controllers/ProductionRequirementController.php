<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\AuthorizationService;
use App\Services\ProductionRequirementService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductionRequirementController extends Controller
{
    public function index(Request $request, ProductionRequirementService $service, AuthorizationService $authorization): View
    {
        $locations = $authorization->accessibleLocations($request->user())->sortBy([['type', 'asc'], ['name', 'asc']]);
        $location = $locations->firstWhere('id', $request->integer('location_id'))
            ?? $locations->firstWhere('type', Location::TYPE_PRODUCTION)
            ?? $locations->first();

        return view('production.requirements', [
            'locations' => $locations,
            'location' => $location,
            'requirements' => $location ? $service->forLocation($location) : [],
        ]);
    }
}
