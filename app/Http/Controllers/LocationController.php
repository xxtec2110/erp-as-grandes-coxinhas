<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(): View
    {
        return view('locations.index', [
            'locations' => Location::query()->orderBy('name')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('locations.create');
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        Location::query()->create($request->validated());

        return redirect()->route('locations.index')->with('success', 'Unidade cadastrada com sucesso.');
    }

    public function edit(Location $location): View
    {
        return view('locations.edit', compact('location'));
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        return redirect()->route('locations.index')->with('success', 'Unidade atualizada com sucesso.');
    }
}
