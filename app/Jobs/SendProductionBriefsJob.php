<?php

namespace App\Jobs;

use App\Models\ProductionUserPolicy;
use App\Services\ProductionNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendProductionBriefsJob implements ShouldQueue
{
    use Queueable;

    public function handle(ProductionNotificationService $service): void
    {
        if (! config('production_operations.briefings_enabled')) {
            return;
        }$now = CarbonImmutable::now(config('app.timezone'));
        ProductionUserPolicy::query()->with(['user.externalIdentities', 'location'])->where('active', true)->whereTime('briefing_time', '<=', $now->format('H:i:s'))->each(fn ($p) => $service->sendBrief($p, $now));
    }
}
