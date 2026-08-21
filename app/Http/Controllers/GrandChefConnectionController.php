<?php

namespace App\Http\Controllers;

use App\Http\Requests\GrandChefConnectionRequest;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Services\AuthorizationService;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrandChefConnectionController extends Controller
{
    public function create(Request $request, Location $location, PdvConnectionAccessService $access): View
    {
        $access->authorizeLocation($request->user(), $location);
        abort_unless($location->active && $location->type === Location::TYPE_STORE, 422);
        abort_if(PdvConnection::query()->where('provider', 'grandchef')->whereBelongsTo($location)->exists(), 409);

        return view('pdv.connection', [
            'connection' => null,
            'selectedLocation' => $location,
            'locations' => collect([$location]),
            'bearerCredentialConfigured' => false,
            'deviceCredentialConfigured' => false,
        ]);
    }

    public function store(GrandChefConnectionRequest $request, Location $location, PdvConnectionService $connections): RedirectResponse
    {
        abort_unless($request->integer('location_id') === $location->id, 422);
        $connection = $connections->create($location, $request->validated(), $request->user());

        return redirect()->route('pdv.index')->with('success', "Integração GrandChef de {$connection->location->name} configurada.");
    }

    public function edit(Request $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvConnectionService $connections, AuthorizationService $authorization): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $locations = $connection->location_id === null
            ? $authorization->accessibleLocations($request->user())->where('type', Location::TYPE_STORE)->filter(fn (Location $location): bool => ! PdvConnection::query()->where('provider', 'grandchef')->whereBelongsTo($location)->whereKeyNot($connection->id)->exists())
            : collect([$connection->location]);

        return view('pdv.connection', [
            'connection' => $connection,
            'selectedLocation' => $connection->location,
            'locations' => $locations,
            'bearerCredentialConfigured' => $connections->bearerCredentialConfigured($connection),
            'deviceCredentialConfigured' => $connections->deviceCredentialConfigured($connection),
        ]);
    }

    public function update(GrandChefConnectionRequest $request, PdvConnection $connection, PdvConnectionService $connections): RedirectResponse
    {
        $connection = $connections->update($connection, $request->validated(), $request->user());

        return redirect()->route('pdv.index')->with('success', "Integração GrandChef de {$connection->location->name} atualizada.");
    }
}
