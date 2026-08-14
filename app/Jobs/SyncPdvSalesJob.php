<?php

namespace App\Jobs;

use App\Models\PdvConnection;
use App\Models\User;
use App\Services\PdvSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncPdvSalesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function handle(PdvSyncService $sync): void
    {
        if (! config('pdv.enabled') || ! config('pdv.sync_enabled')) {
            return;
        }
        $user = User::query()->where('is_super_admin', true)->first();
        if (! $user) {
            return;
        }
        PdvConnection::query()->where('enabled', true)->each(fn ($connection) => $sync->sync($connection, $user));
    }
}
