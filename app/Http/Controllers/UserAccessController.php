<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserAccessRequest;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\UserAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserAccessController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::query()->with('roles')->orderBy('name')->paginate(20)]);
    }

    public function edit(User $user, AuthorizationService $authorization): View
    {
        return view('users.access', ['managedUser' => $user->load(['roles', 'permissions', 'locations']), 'roles' => Role::query()->orderBy('label')->get(), 'permissions' => Permission::query()->orderBy('group')->orderBy('label')->get(), 'locations' => Location::query()->orderBy('name')->get(), 'effective' => $authorization->effectivePermissions($user)]);
    }

    public function update(UserAccessRequest $request, User $user, UserAccessService $service): RedirectResponse
    {
        $service->update($user, $request->validated(), $request->user());

        return back()->with('success', 'Acessos atualizados e auditados.');
    }
}
