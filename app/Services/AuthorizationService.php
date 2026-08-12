<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class AuthorizationService
{
    public function allows(User $user, string $permission, Location|int|null $location = null): bool
    {
        if ($user->is_super_admin) {
            return true;
        }
        $direct = $user->permissions()->where('name', $permission)->first();
        $hasPermission = $direct !== null
            ? (bool) $direct->pivot->allowed
            : $user->roles()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->exists();
        if (! $hasPermission) {
            return false;
        }
        if ($location === null) {
            return true;
        }
        if ($user->all_locations_access) {
            return true;
        }
        $id = $location instanceof Location ? $location->id : $location;

        return $user->locations()->whereKey($id)->exists();
    }

    public function authorize(User $user, string $permission, Location|int|null $location = null): void
    {
        if (! $this->allows($user, $permission, $location)) {
            throw new AuthorizationException('Você não possui permissão para esta operação ou unidade.');
        }
    }

    /** @return Collection<int, Location> */
    public function accessibleLocations(User $user): Collection
    {
        return ($user->is_super_admin || $user->all_locations_access)
            ? Location::query()->where('active', true)->orderBy('name')->get()
            : $user->locations()->where('active', true)->orderBy('name')->get();
    }

    /** @return array<int, string> */
    public function effectivePermissions(User $user): array
    {
        if ($user->is_super_admin) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }
        $role = $user->roles()->with('permissions')->get()->flatMap->permissions->pluck('name')->unique();
        foreach ($user->permissions()->get() as $permission) {
            $role = $permission->pivot->allowed ? $role->push($permission->name) : $role->reject(fn ($name) => $name === $permission->name);
        }

        return $role->unique()->sort()->values()->all();
    }
}
