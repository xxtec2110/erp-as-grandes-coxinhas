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
        $requestedId = $request->integer('location_id');
        if ($request->has('location_id') && ! $locations->contains('id', $requestedId)) {
            abort(403, 'Você não possui acesso a esta unidade.');
        }
        $location = $locations->firstWhere('id', $requestedId)
            ?? $locations->firstWhere('type', Location::TYPE_PRODUCTION)
            ?? $locations->first();

        return view('production.requirements', [
            'locations' => $locations,
            'location' => $location,
            'requirements' => $location ? $service->forLocation($location) : [],
        ]);
    }
}
