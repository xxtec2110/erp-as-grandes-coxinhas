<?php

namespace App\Pdv;

use App\Models\PdvConnection;

class PdvProviderManager
{
    public function for(PdvConnection $connection): PdvProviderInterface
    {
        return match ($connection->provider) {
            'fake' => app(FakePdvProvider::class),
            'grandchef' => app(GrandChefPdvProvider::class),
            default => throw new IntegrationNotConfiguredException('Provider de PDV desconhecido.'),
        };
    }
}
