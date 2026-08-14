<?php

use App\Jobs\CheckMissingProductionJob;
use App\Jobs\SendProductionBriefsJob;
use App\Jobs\SyncPdvSalesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendProductionBriefsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new CheckMissingProductionJob)->everyMinute()->withoutOverlapping();
$pdvInterval = (int) config('pdv.sync_interval_minutes', 0);
if ($pdvInterval > 0) {
    Schedule::job(new SyncPdvSalesJob)->cron("*/{$pdvInterval} * * * *")->withoutOverlapping();
}
