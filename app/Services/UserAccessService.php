<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccessService
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @param array<string, mixed> $data */
    public function update(User $target, array $data, User $actor, string $source = 'web'): User
    {
        return DB::transaction(function () use ($target, $data, $actor, $source): User {
            $before = ['roles' => $target->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all(), 'locations' => $target->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->all_locations_access, 'default_location_id' => $target->default_location_id];
            $roleIds = array_map('intval', $data['role_ids'] ?? $before['roles']);
            $locationIds = array_map('intval', $data['location_ids'] ?? $before['locations']);
            $allLocations = array_key_exists('all_locations_access', $data) ? (bool) $data['all_locations_access'] : (bool) $before['all_locations_access'];
            $defaultLocationId = array_key_exists('default_location_id', $data)
                ? ($data['default_location_id'] === null ? null : (int) $data['default_location_id'])
                : $before['default_location_id'];

            $permissionChange = array_key_exists('role_ids', $data) || array_key_exists('permission_overrides', $data);
            $locationChange = array_key_exists('location_ids', $data) || array_key_exists('all_locations_access', $data) || array_key_exists('default_location_id', $data);
            if ($permissionChange && ! $this->authorization->allows($actor, 'user_permissions.manage')) {
                throw new AuthorizationException('Somente o Admin Master pode alterar perfis e permissões.');
            }
            if ($locationChange && ! $this->authorization->allows($actor, 'user_locations.manage')) {
                throw new AuthorizationException('Somente o Admin Master pode alterar o acesso às unidades.');
            }
            if ($defaultLocationId !== null && ! $allLocations && ! in_array($defaultLocationId, $locationIds, true)) {
                throw ValidationException::withMessages(['default_location_id' => 'A unidade padrão deve estar entre as unidades autorizadas.']);
            }

            if ($permissionChange) {
                $target->roles()->sync($roleIds);
                $overrides = [];
                foreach (($data['permission_overrides'] ?? []) as $permissionId => $value) {
                    if ($value !== 'inherit') {
                        $overrides[(int) $permissionId] = ['allowed' => $value === 'allow'];
                    }
                }
                $target->permissions()->sync($overrides);
            }
            if ($locationChange) {
                $target->locations()->sync($locationIds);
                $target->update(['all_locations_access' => $allLocations, 'default_location_id' => $defaultLocationId]);
            }
            $after = ['roles' => $target->roles()->pluck('roles.id')->all(), 'locations' => $target->locations()->pluck('locations.id')->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->fresh()->all_locations_access, 'default_location_id' => $target->fresh()->default_location_id];
            AuthorizationAudit::query()->create(['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'change_type' => 'access_updated', 'subject' => 'user_access', 'previous_value' => $before, 'new_value' => $after, 'source' => $source]);

            return $target->refresh();
        });
    }
}
