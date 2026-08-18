<?php

namespace Database\Seeders;

use App\Services\OperationalLocationService;
use Illuminate\Database\Seeder;

class OperationalLocationsSeeder extends Seeder
{
    public function run(OperationalLocationService $locations): void
    {
        $locations->ensureRequiredLocations();
    }
}
