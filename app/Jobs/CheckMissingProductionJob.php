<?php

namespace App\Jobs;

use App\Models\ProductionUserPolicy;
use App\Services\ProductionNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckMissingProductionJob implements ShouldQueue
{
    use Queueable;

    public function handle(ProductionNotificationService $service): void
    {
        if (! config('production_operations.alerts_enabled')) {
            return;
        }$now = CarbonImmutable::now(config('app.timezone'));
        ProductionUserPolicy::query()->with(['user.externalIdentities', 'location'])->where('active', true)->whereTime('alert_time', '<=', $now->format('H:i:s'))->each(fn ($p) => $service->sendMissingAlert($p, $now));
    }
}
