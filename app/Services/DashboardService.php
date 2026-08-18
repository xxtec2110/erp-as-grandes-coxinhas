<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;

class DashboardService
{
    public function __construct(private DashboardVisibilityService $visibility, private DashboardMetricsService $metrics) {}

    /** @return array<int, array<string, mixed>> */
    public function widgets(User $user, Location $location, string $start, string $end): array
    {
        $visible = $this->visibility->visible($user);
        $data = $this->metrics->load($visible, $user, $location, $start, $end);

        return $visible->map(fn (array $widget) => [...$widget, 'data' => $data[$widget['provider']] ?? null])->all();
    }
}
