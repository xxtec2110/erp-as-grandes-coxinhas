<?php

namespace App\Http\Controllers;

use App\Http\Requests\GrandChefReportRequest;
use App\Models\PdvConnection;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvSalesReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class PdvReconciliationController extends Controller
{
    public function __invoke(GrandChefReportRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvSalesReconciliationService $reconciliation): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = $request->validated('from') ?? now($timezone)->toDateString();
        $to = $request->validated('to') ?? $from;
        $result = $reconciliation->period($connection, CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone));

        return view('pdv.reconciliation', compact('connection', 'from', 'to', 'result'));
    }
}
