<?php

namespace App\Services;

use App\Models\AuthorizationAudit;
use App\Models\Location;
use App\Models\Permission;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentAccessManagementService
{
    public function __construct(private AuthorizationService $authorization) {}

    /** @return array<string, mixed> */
    public function prepareAgentInput(string $tool, array $data, User $actor): array
    {
        $permission = str_contains($tool, '.location') || str_contains($tool, '.locations') || str_contains($tool, 'default_location')
            ? 'user_locations.manage'
            : 'user_permissions.manage';
        $this->authorization->authorize($actor, $permission);
        $target = $this->user($data);

        return [...$data, 'target_user_id' => $target->id, 'target_user_name' => $target->name];
    }

    public function permission(array $data, User $actor, bool $allowed, array $context = []): User
    {
        $this->authorization->authorize($actor, 'user_permissions.manage');

        return DB::transaction(function () use ($data, $actor, $allowed, $context): User {
            $target = $this->user($data);
            if ($this->existingAudit($data) !== null) {
                return $target->refresh();
            }
            $permission = Permission::query()->where('name', $data['permission'])->firstOrFail();
            $before = $target->permissions()->whereKey($permission->id)->first()?->pivot->allowed;
            $target->permissions()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
            if ($permission->name === 'agent.audio.use') {
                $target->externalIdentities()->update(['voice_allowed' => $allowed]);
            }
            $this->audit($actor, $target, 'permission_changed', ['permission' => $permission->name, 'allowed' => $before], ['permission' => $permission->name, 'allowed' => $allowed], $data, $context);

            return $target->refresh();
        });
    }

    public function location(array $data, User $actor, bool $allowed, array $context = []): User
    {
        $this->authorization->authorize($actor, 'user_locations.manage');

        return DB::transaction(function () use ($data, $actor, $allowed, $context): User {
            $target = $this->user($data);
            if ($this->existingAudit($data) !== null) {
                return $target->refresh();
            }
            if ($target->all_locations_access && ! $allowed) {
                throw new DomainException('O usuário possui acesso a todas as unidades. Use o comando “somente” para definir uma lista explícita.');
            }
            $location = Location::query()->where('active', true)->findOrFail($data['location_id']);
            $before = $this->snapshot($target);
            $allowed ? $target->locations()->syncWithoutDetaching([$location->id]) : $target->locations()->detach($location->id);
            if (! $allowed && $target->default_location_id === $location->id) {
                $target->update(['default_location_id' => null]);
            }
            $this->audit($actor, $target, 'locations_changed', $before, $this->snapshot($target->refresh()), $data, $context);

            return $target->refresh();
        });
    }

    public function replaceLocations(array $data, User $actor, array $context = []): User
    {
        $this->authorization->authorize($actor, 'user_locations.manage');

        return DB::transaction(function () use ($data, $actor, $context): User {
            $target = $this->user($data);
            if ($this->existingAudit($data) !== null) {
                return $target->refresh();
            }
            $locationIds = collect($data['location_ids'] ?? [$data['location_id'] ?? null])
                ->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $activeIds = Location::query()->where('active', true)->whereKey($locationIds)->pluck('id');
            if ($locationIds->isEmpty() || $activeIds->count() !== $locationIds->count()) {
                throw new DomainException('Informe ao menos uma unidade ativa e válida.');
            }
            $before = $this->snapshot($target);
            $target->locations()->sync($activeIds);
            $target->update([
                'all_locations_access' => false,
                'default_location_id' => $activeIds->contains($target->default_location_id) ? $target->default_location_id : $activeIds->first(),
            ]);
            $after = $this->snapshot($target->refresh());
            $this->audit($actor, $target, 'locations_replaced', $before, $after, $data, $context);

            return $target->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function locations(array $data, User $actor): array
    {
        $this->authorization->authorize($actor, 'user_locations.manage');
        $target = $this->user($data);

        return [
            'target_user_id' => $target->id,
            'target_user_name' => $target->name,
            'all_locations_access' => $target->all_locations_access,
            'default_location_id' => $target->default_location_id,
            'locations' => ($target->all_locations_access
                ? Location::query()->where('active', true)->orderBy('name')->get()
                : $target->locations()->where('active', true)->orderBy('name')->get())
                ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name, 'type' => $location->type])
                ->values()->all(),
        ];
    }

    public function defaultLocation(array $data, User $actor, array $context = []): User
    {
        $this->authorization->authorize($actor, 'user_locations.manage');
        $target = $this->user($data);
        if ($this->existingAudit($data) !== null) {
            return $target->refresh();
        }
        $location = Location::query()->where('active', true)->findOrFail($data['location_id']);
        if (! $target->all_locations_access && ! $target->locations()->whereKey($location)->exists()) {
            throw new DomainException('A unidade padrão precisa estar autorizada para o usuário.');
        }
        $before = $this->snapshot($target);
        $target->update(['default_location_id' => $location->id]);
        $this->audit($actor, $target, 'default_location_changed', $before, $this->snapshot($target->refresh()), $data, $context);

        return $target->refresh();
    }

    public function preview(string $tool, array $data): string
    {
        $target = $this->user($data);
        $before = $this->snapshot($target);
        $location = isset($data['location_id']) ? Location::query()->find($data['location_id']) : null;

        if ($tool === 'agent.access.locations.replace') {
            $locationIds = collect($data['location_ids'] ?? [$data['location_id']])->map(fn ($id) => (int) $id)->all();
            $locations = Location::query()->whereKey($locationIds)->orderBy('name')->pluck('name');
            $removed = Location::query()->whereIn('id', array_diff($before['locations'], $locationIds))->pluck('name');

            return "ALTERAÇÃO DE ACESSO ÀS UNIDADES\n\nUsuário:\n{$target->name}\n\nMANTER:\n✓ {$locations->implode("\n✓ ")}\n\nREMOVER:\n".($removed->isEmpty() ? 'Nenhuma' : '✕ '.$removed->implode("\n✕ "))."\n\nNenhuma outra permissão será alterada.\n\nConfirmar?";
        }

        $verb = $tool === 'agent.access.location.grant' ? 'ADICIONAR' : 'REMOVER';

        return "ALTERAÇÃO DE ACESSO ÀS UNIDADES\n\nUsuário:\n{$target->name}\n\n{$verb}:\n".($location?->name ?? 'Unidade não informada')."\n\nNenhuma outra permissão será alterada.\n\nConfirmar?";
    }

    /** @return array<string, mixed> */
    private function snapshot(User $target): array
    {
        return [
            'all_locations_access' => (bool) $target->all_locations_access,
            'default_location_id' => $target->default_location_id,
            'locations' => $target->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    private function user(array $data): User
    {
        if (isset($data['target_user_id'])) {
            return User::query()->findOrFail($data['target_user_id']);
        }
        $needle = Str::lower(Str::ascii(trim((string) ($data['target_user_name'] ?? ''))));
        $matches = User::query()->where('active', true)->orderBy('name')->get()->filter(function (User $user) use ($needle): bool {
            $candidate = Str::lower(Str::ascii($user->name));

            return $candidate === $needle || str_starts_with($candidate, $needle.' ') || collect(explode(' ', $candidate))->contains($needle);
        })->values();
        if ($matches->count() !== 1) {
            throw new DomainException('Usuário alvo não encontrado ou ambíguo. Informe o nome completo.');
        }

        return $matches->sole();
    }

    private function existingAudit(array $data): ?AuthorizationAudit
    {
        return isset($data['idempotency_key'])
            ? AuthorizationAudit::query()->where('idempotency_key', $data['idempotency_key'])->first()
            : null;
    }

    private function audit(User $actor, User $target, string $type, mixed $before, mixed $after, array $data, array $context): void
    {
        AuthorizationAudit::query()->create([
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'change_type' => $type,
            'subject' => 'agent_access',
            'previous_value' => ['value' => $before],
            'new_value' => ['value' => $after],
            'source' => 'agent',
            'context' => $context,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
    }
}
