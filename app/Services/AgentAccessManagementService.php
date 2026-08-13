<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class AgentAccessManagementService
{
    public function permission(array $data, User $actor, bool $allowed): User
    {
        return DB::transaction(function () use ($data, $actor, $allowed): User {
            $target = $this->user($data);
            $permission = Permission::query()->where('name', $data['permission'])->firstOrFail();
            $before = $target->permissions()->whereKey($permission->id)->first()?->pivot->allowed;
            $target->permissions()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
            if ($permission->name === 'agent.audio.use') {
                $target->externalIdentities()->update(['voice_allowed' => $allowed]);
            }
            $this->audit($actor, $target, 'permission_changed', ['permission' => $permission->name, 'allowed' => $before], ['permission' => $permission->name, 'allowed' => $allowed]);

            return $target->refresh();
        });
    }

    public function location(array $data, User $actor, bool $allowed): User
    {
        return DB::transaction(function () use ($data, $actor, $allowed): User {
            $target = $this->user($data);
            $location = Location::query()->findOrFail($data['location_id']);
            $before = $target->locations()->pluck('locations.id')->all();
            $allowed ? $target->locations()->syncWithoutDetaching([$location->id]) : $target->locations()->detach($location->id);
            if (! $allowed && $target->default_location_id === $location->id) {
                $target->update(['default_location_id' => null]);
            }
            $this->audit($actor, $target, 'locations_changed', $before, $target->locations()->pluck('locations.id')->all());

            return $target->refresh();
        });
    }

    public function defaultLocation(array $data, User $actor): User
    {
        $target = $this->user($data);
        $location = Location::query()->findOrFail($data['location_id']);
        if (! $target->all_locations_access && ! $target->locations()->whereKey($location)->exists()) {
            throw new DomainException('A unidade padrão precisa estar autorizada para o usuário.');
        }
        $before = $target->default_location_id;
        $target->update(['default_location_id' => $location->id]);
        $this->audit($actor, $target, 'default_location_changed', $before, $location->id);

        return $target->refresh();
    }

    private function user(array $data): User
    {
        if (isset($data['target_user_id'])) {
            return User::query()->findOrFail($data['target_user_id']);
        }
        $matches = User::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['target_user_name'] ?? ''))])->get();
        if ($matches->count() !== 1) {
            throw new DomainException('Usuário alvo não encontrado ou ambíguo. Informe o nome completo.');
        }

        return $matches->sole();
    }

    private function audit(User $actor, User $target, string $type, mixed $before, mixed $after): void
    {
        AuthorizationAudit::query()->create(['actor_user_id' => $actor->id, 'target_user_id' => $target->id, 'change_type' => $type, 'subject' => 'agent_access', 'previous_value' => ['value' => $before], 'new_value' => ['value' => $after], 'source' => 'agent']);
    }
}
