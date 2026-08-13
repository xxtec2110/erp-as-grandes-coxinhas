<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccessService
{
    /** @param array<string, mixed> $data */
    public function update(User $target, array $data, User $actor, string $source = 'web'): User
    {
        return DB::transaction(function () use ($target, $data, $actor, $source): User {
            $locationIds = array_map('intval', $data['location_ids'] ?? []);
            $defaultLocationId = isset($data['default_location_id']) ? (int) $data['default_location_id'] : null;
            if ($defaultLocationId !== null && ! ($data['all_locations_access'] ?? false) && ! in_array($defaultLocationId, $locationIds, true)) {
                throw ValidationException::withMessages(['default_location_id' => 'A unidade padrão deve estar entre as unidades autorizadas.']);
            }
            $before = ['roles' => $target->roles()->pluck('roles.id')->all(), 'locations' => $target->locations()->pluck('locations.id')->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->all_locations_access, 'default_location_id' => $target->default_location_id];
            $target->roles()->sync($data['role_ids'] ?? []);
            $target->locations()->sync($locationIds);
            $target->update(['all_locations_access' => (bool) ($data['all_locations_access'] ?? false), 'default_location_id' => $defaultLocationId]);
            $overrides = [];
            foreach (($data['permission_overrides'] ?? []) as $permissionId => $value) {
                if ($value !== 'inherit') {
                    $overrides[(int) $permissionId] = ['allowed' => $value === 'allow'];
                }
            }
            $target->permissions()->sync($overrides);
            $after = ['roles' => $target->roles()->pluck('roles.id')->all(), 'locations' => $target->locations()->pluck('locations.id')->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->fresh()->all_locations_access, 'default_location_id' => $target->fresh()->default_location_id];
            AuthorizationAudit::query()->create(['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'change_type' => 'access_updated', 'subject' => 'user_access', 'previous_value' => $before, 'new_value' => $after, 'source' => $source]);

            return $target->refresh();
        });
    }
}
