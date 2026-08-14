<?php

namespace App\Http\Controllers;

use App\Services\AgentUsageReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentUsageReportController extends Controller
{
    public function __invoke(Request $request, AgentUsageReportService $service): View
    {
        $start = CarbonImmutable::parse($request->input('start', now()->startOfMonth()->toDateString()), config('app.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($request->input('end', now()->toDateString()), config('app.timezone'))->endOfDay();

        return view('agent.usage', ['rows' => $service->summarize($start, $end), 'start' => $start, 'end' => $end]);
    }
}
