<?php

namespace App\Services;

use App\Agent\AgentToolRegistry;
use App\Models\User;

class AgentCapabilityService
{
    public function __construct(private AgentToolRegistry $registry, private AuthorizationService $authorization) {}

    /** @return array<int, string> */
    public function forUser(User $user): array
    {
        return collect($this->registry->all())
            ->filter(fn ($tool): bool => $this->authorization->allows($user, $tool->permission)
                && ($tool->name !== 'production.orders.complete_batch' || $this->authorization->allows($user, 'production.orders.create')))
            ->map(fn ($tool): string => $tool->capabilityLabel())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
