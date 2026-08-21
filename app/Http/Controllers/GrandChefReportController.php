<?php

namespace App\Http\Controllers;

use App\Http\Requests\GrandChefReportRequest;
use App\Models\PdvConnection;
use App\Pdv\GrandChefRequestException;
use App\Pdv\IntegrationNotConfiguredException;
use App\Services\GrandChefSalesReportService;
use App\Services\PdvConnectionAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrandChefReportController extends Controller
{
    public function index(GrandChefReportRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, GrandChefSalesReportService $reports): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = $request->validated('from') ?? now($timezone)->toDateString();
        $to = $request->validated('to') ?? $from;
        $report = null;
        $error = null;

        if ($request->filled(['from', 'to'])) {
            try {
                $report = $reports->report(
                    $connection,
                    CarbonImmutable::parse($from, $timezone),
                    CarbonImmutable::parse($to, $timezone),
                );
            } catch (GrandChefRequestException|IntegrationNotConfiguredException $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('pdv.report', compact('connection', 'from', 'to', 'report', 'error'));
    }

    public function show(Request $request, PdvConnection $connection, string $externalSaleId, PdvConnectionAccessService $access, GrandChefSalesReportService $reports): View
    {
        $access->authorizeConnection($request->user(), $connection);
        abort_unless((bool) preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $externalSaleId), 404);
        $sale = null;
        $error = null;

        try {
            $sale = $reports->sale($connection, $externalSaleId);
        } catch (GrandChefRequestException|IntegrationNotConfiguredException $exception) {
            $error = $exception->getMessage();
        }

        return view('pdv.order', compact('connection', 'sale', 'externalSaleId', 'error'));
    }
}
