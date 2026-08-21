<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvConnectionService
{
    public function __construct(private PdvConnectionAccessService $access) {}

    public function create(Location $location, array $data, User $user): PdvConnection
    {
        $this->access->authorizeLocation($user, $location);
        $this->assertStore($location);
        if ((int) $data['location_id'] !== $location->id) {
            throw ValidationException::withMessages(['location_id' => 'A unidade informada não corresponde à configuração solicitada.']);
        }

        if (PdvConnection::query()->where('provider', 'grandchef')->whereBelongsTo($location)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Esta unidade já possui uma conexão GrandChef.']);
        }

        return DB::transaction(function () use ($location, $data, $user): PdvConnection {
            $connection = new PdvConnection(['provider' => 'grandchef', 'location_id' => $location->id, 'created_by' => $user->id]);

            return $this->persist($connection, $data);
        });
    }

    public function update(PdvConnection $connection, array $data, User $user): PdvConnection
    {
        $this->access->authorizeConnection($user, $connection);

        return DB::transaction(function () use ($connection, $data, $user): PdvConnection {
            $location = Location::query()->findOrFail($data['location_id']);
            $this->access->authorizeLocation($user, $location);
            $this->assertStore($location);

            if ($connection->location_id !== null && $connection->location_id !== $location->id) {
                throw ValidationException::withMessages(['location_id' => 'Uma conexão já vinculada não pode ser movida para outra unidade.']);
            }

            $duplicate = PdvConnection::query()
                ->where('provider', 'grandchef')
                ->where('location_id', $location->id)
                ->whereKeyNot($connection->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['location_id' => 'Esta unidade já possui uma conexão GrandChef.']);
            }

            $connection->location_id = $location->id;
            $connection->created_by ??= $user->id;

            return $this->persist($connection, $data);
        });
    }

    public function credentialConfigured(PdvConnection $connection): bool
    {
        return $this->bearerCredentialConfigured($connection) && $this->deviceCredentialConfigured($connection);
    }

    public function bearerCredentialConfigured(PdvConnection $connection): bool
    {
        return filled(data_get($connection->encrypted_credentials, 'bearer_token'));
    }

    public function deviceCredentialConfigured(PdvConnection $connection): bool
    {
        return filled(data_get($connection->encrypted_credentials, 'device_token'));
    }

    private function persist(PdvConnection $connection, array $data): PdvConnection
    {
        $credentials = $connection->encrypted_credentials ?? [];
        if (filled($data['bearer_token'] ?? null)) {
            $credentials['bearer_token'] = $this->normalizeCredential((string) $data['bearer_token'], 'Bearer');
        }
        if (filled($data['device_token'] ?? null)) {
            $credentials['device_token'] = $this->normalizeCredential((string) $data['device_token'], 'Device');
        }

        $endpoint = trim((string) $data['endpoint']);
        if (($data['enabled'] ?? false) && ($endpoint === '' || blank($credentials['bearer_token'] ?? null) || blank($credentials['device_token'] ?? null))) {
            throw ValidationException::withMessages(['enabled' => 'Informe endpoint, credencial Bearer e credencial Device antes de ativar a conexão.']);
        }

        $configured = $endpoint !== ''
            && filled($credentials['bearer_token'] ?? null)
            && filled($credentials['device_token'] ?? null);

        $connection->fill([
            'provider' => 'grandchef',
            'name' => $data['name'],
            'enabled' => (bool) $data['enabled'],
            'configuration' => ['endpoint' => $endpoint],
            'encrypted_credentials' => $credentials === [] ? null : $credentials,
            'status' => $configured ? ($connection->status === 'healthy' ? 'healthy' : 'configured') : 'not_configured',
        ])->save();

        return $connection->fresh(['location']);
    }

    private function normalizeCredential(string $credential, string $prefix): string
    {
        return trim((string) preg_replace('/^'.preg_quote($prefix, '/').'\s+/i', '', trim($credential)));
    }

    private function assertStore(Location $location): void
    {
        if (! $location->active || $location->type !== Location::TYPE_STORE) {
            throw ValidationException::withMessages(['location_id' => 'GrandChef só pode ser configurado em uma unidade ativa do tipo loja.']);
        }
    }
}
