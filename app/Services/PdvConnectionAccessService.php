<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use Illuminate\Auth\Access\AuthorizationException;

class PdvConnectionAccessService
{
    public function __construct(private AuthorizationService $authorization) {}

    public function authorizeLocation(User $user, Location $location): void
    {
        $this->authorization->authorize($user, 'pdv.manage', $location);
    }

    public function authorizeConnection(User $user, PdvConnection $connection): void
    {
        if ($connection->location_id === null) {
            if (! $user->is_super_admin) {
                throw new AuthorizationException('Somente o Admin Master pode administrar uma conexão legada sem unidade.');
            }

            return;
        }

        $location = $connection->location;
        if ($location === null) {
            throw new AuthorizationException('A unidade desta conexão não existe.');
        }

        $this->authorizeLocation($user, $location);
    }

    public function assertOperationalScope(PdvConnection $connection): Location
    {
        $location = $connection->location;

        if ($connection->location_id === null || $location === null || ! $location->active) {
            throw new IntegrationNotConfiguredException('A conexão GrandChef precisa estar vinculada a uma unidade ativa.');
        }

        if ($connection->provider === 'grandchef' && $location->type !== Location::TYPE_STORE) {
            throw new IntegrationNotConfiguredException('GrandChef só pode ser ativado em uma unidade do tipo loja.');
        }

        return $location;
    }
}
