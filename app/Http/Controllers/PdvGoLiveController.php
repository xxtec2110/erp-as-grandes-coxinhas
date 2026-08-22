<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvOrderPeriodRequest;
use App\Http\Requests\PdvProductBatchConfirmRequest;
use App\Http\Requests\PdvProductBatchOnboardingRequest;
use App\Models\PdvConnection;
use App\Services\AuthorizationService;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvGoLiveService;
use App\Services\PdvProductBatchOnboardingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PdvGoLiveController extends Controller
{
    public function index(PdvOrderPeriodRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvGoLiveService $goLive, AuthorizationService $authorization): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        [$from, $to, $fromDate, $toDate] = $this->period($request->validated(), $connection);

        return view('pdv.go-live', [
            'connection' => $connection->load('location'),
            'from' => $from,
            'to' => $to,
            'goLive' => $goLive->build($connection, $fromDate, $toDate),
            'canCreateProducts' => $authorization->allows($request->user(), 'products.create'),
        ]);
    }

    public function previewProducts(PdvProductBatchOnboardingRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvProductBatchOnboardingService $onboarding): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = CarbonImmutable::parse((string) $request->validated('from'), $timezone);
        $to = CarbonImmutable::parse((string) $request->validated('to'), $timezone);
        $preview = $onboarding->preview($connection, $request->user(), $from, $to, $request->selectedRows());

        return view('pdv.product-batch-preview', compact('connection', 'from', 'to', 'preview'));
    }

    public function confirmProducts(PdvProductBatchConfirmRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvProductBatchOnboardingService $onboarding): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $result = $onboarding->confirm($connection, $request->user(), (string) $request->validated('preview_token'));

        return redirect()->route('pdv.go-live', $connection)
            ->with('success', $result['created'].' Product(s) oficial(is) criado(s). Nenhum mapping, venda ou estoque foi alterado.');
    }

    /** @param array<string,mixed> $validated
     * @return array{0:string,1:string,2:CarbonImmutable,3:CarbonImmutable}
     */
    private function period(array $validated, PdvConnection $connection): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $latest = $connection->orders()->whereNotNull('external_completed_at')->max('external_completed_at');
        $default = $latest === null ? now($timezone)->toDateString() : CarbonImmutable::parse($latest)->setTimezone($timezone)->toDateString();
        $from = (string) ($validated['from'] ?? $default);
        $to = (string) ($validated['to'] ?? $from);

        return [$from, $to, CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone)];
    }
}
