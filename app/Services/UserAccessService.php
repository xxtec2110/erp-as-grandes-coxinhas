<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    /** @param array<string, mixed> $data */
    public function update(User $target, array $data, User $actor, string $source = 'web'): User
    {
        return DB::transaction(function () use ($target, $data, $actor, $source): User {
            $before = ['roles' => $target->roles()->pluck('roles.id')->all(), 'locations' => $target->locations()->pluck('locations.id')->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->all_locations_access];
            $target->roles()->sync($data['role_ids'] ?? []);
            $target->locations()->sync($data['location_ids'] ?? []);
            $target->update(['all_locations_access' => (bool) ($data['all_locations_access'] ?? false)]);
            $overrides = [];
            foreach (($data['permission_overrides'] ?? []) as $permissionId => $value) {
                if ($value !== 'inherit') {
                    $overrides[(int) $permissionId] = ['allowed' => $value === 'allow'];
                }
            }
            $target->permissions()->sync($overrides);
            $after = ['roles' => $target->roles()->pluck('roles.id')->all(), 'locations' => $target->locations()->pluck('locations.id')->all(), 'overrides' => $target->permissions()->pluck('allowed', 'permissions.id')->all(), 'all_locations_access' => $target->fresh()->all_locations_access];
            AuthorizationAudit::query()->create(['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'change_type' => 'access_updated', 'subject' => 'user_access', 'previous_value' => $before, 'new_value' => $after, 'source' => $source]);

            return $target->refresh();
        });
    }
}
