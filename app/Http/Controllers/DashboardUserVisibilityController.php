<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardWidgetVisibilityRequest;
use App\Models\User;
use App\Services\DashboardUserVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardUserVisibilityController extends Controller
{
    public function update(DashboardWidgetVisibilityRequest $request, User $user, DashboardUserVisibilityService $service): RedirectResponse
    {
        $service->update($user, $request->validated('widgets'), $request->user());

        return back()->with('success', 'Visibilidade do dashboard atualizada e auditada.');
    }

    public function reset(Request $request, User $user, DashboardUserVisibilityService $service): RedirectResponse
    {
        $service->reset($user, $request->user());

        return back()->with('success', 'Dashboard restaurado para o padrão herdado.');
    }
}
