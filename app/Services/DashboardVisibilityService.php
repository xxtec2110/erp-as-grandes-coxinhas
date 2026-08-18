<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDashboardWidget;
use Illuminate\Support\Collection;

class DashboardVisibilityService
{
    public function __construct(private DashboardWidgetRegistry $registry, private AuthorizationService $authorization) {}

    /** @return Collection<int, array<string, mixed>> */
    public function visible(User $user): Collection
    {
        return $this->configuration($user)->where('effective_visible', true)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function configuration(User $user): Collection
    {
        $preferences = $user->dashboardWidgets()->get()->keyBy('widget_key');
        $effectivePermissions = $user->is_super_admin
            ? collect()
            : collect($this->authorization->effectivePermissions($user));

        return $this->registry->all()->map(function (array $widget) use ($user, $preferences, $effectivePermissions): array {
            /** @var UserDashboardWidget|null $preference */
            $preference = $preferences->get($widget['key']);
            $available = $user->is_super_admin || collect($widget['permissions'])->every(
                fn (string $permission) => $effectivePermissions->contains($permission),
            );
            $effectiveVisible = $user->is_super_admin || ($available && match ($preference?->visibility) {
                UserDashboardWidget::SHOW => true,
                UserDashboardWidget::HIDE => false,
                default => $widget['defaultVisible'],
            });

            return [
                ...$widget,
                'available' => $available,
                'preference' => $preference?->visibility ?? 'inherit',
                'effective_visible' => $effectiveVisible,
                'missing_permissions' => $user->is_super_admin ? [] : collect($widget['permissions'])
                    ->reject(fn (string $permission) => $effectivePermissions->contains($permission))
                    ->values()->all(),
            ];
        });
    }
}
