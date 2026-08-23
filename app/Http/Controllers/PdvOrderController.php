<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvOrderImportRequest;
use App\Http\Requests\PdvOrderPeriodRequest;
use App\Http\Requests\PdvOrderReversalRequest;
use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Pdv\GrandChefRequestException;
use App\Pdv\IntegrationNotConfiguredException;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvOrderImportPlanService;
use App\Services\PdvOrderImportService;
use App\Services\PdvOrderPreparationService;
use App\Services\PdvOrderPreviewService;
use App\Services\PdvOrderReversalService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PdvOrderController extends Controller
{
    public function index(PdvOrderPeriodRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvOrderPreviewService $preview): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = $request->validated('from') ?? now($timezone)->toDateString();
        $to = $request->validated('to') ?? $from;
        $result = $preview->period(
            $connection,
            CarbonImmutable::parse($from, $timezone),
            CarbonImmutable::parse($to, $timezone),
        );

        return view('pdv.staging.index', compact('connection', 'from', 'to', 'result'));
    }

    public function prepare(PdvOrderPeriodRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvOrderPreparationService $preparation): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');

        try {
            $result = $preparation->prepare(
                $connection,
                CarbonImmutable::parse($from, $timezone),
                CarbonImmutable::parse($to, $timezone),
            );
        } catch (GrandChefRequestException|IntegrationNotConfiguredException|DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('pdv.staging.index', [$connection, 'from' => $from, 'to' => $to])
            ->with('success', "{$result['staged']} pedido(s) preparado(s) para conferência. Nenhuma venda ou baixa de estoque foi registrada.");
    }

    public function show(Request $request, PdvConnection $connection, PdvOrder $order, PdvConnectionAccessService $access, PdvOrderImportPlanService $plans, PdvOrderReversalService $reversals): View
    {
        $access->authorizeConnection($request->user(), $connection);
        abort_unless($order->pdv_connection_id === $connection->id, 404);
        $importPlan = $plans->plan($order);

        return view('pdv.staging.show', ['connection' => $connection, 'preview' => ['order' => $order, 'reconciliation' => $importPlan['reconciliation']], 'importPlan' => $importPlan, 'reversalEligible' => $reversals->eligible($order)]);
    }

    public function confirmImport(PdvOrderImportRequest $request, PdvConnection $connection, PdvOrder $order, PdvConnectionAccessService $access, PdvOrderImportService $imports): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        abort_unless($order->pdv_connection_id === $connection->id, 404);

        try {
            $result = $imports->execute($order, $request->user());
        } catch (IntegrationNotConfiguredException|DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('pdv.staging.show', [$connection, $order])
            ->with('success', $result['status'] === 'imported' ? 'Pedido importado atomicamente.' : 'O pedido já possuía uma transação oficial; nenhum efeito foi duplicado.');
    }

    public function reverse(PdvOrderReversalRequest $request, PdvConnection $connection, PdvOrder $order, PdvConnectionAccessService $access, PdvOrderReversalService $reversals): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        abort_unless($order->pdv_connection_id === $connection->id, 404);

        try {
            $reversals->reverse($order, $request->user(), (string) $request->validated('reason'), (string) $request->validated('idempotency_key'));
        } catch (IntegrationNotConfiguredException|DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('pdv.staging.show', [$connection, $order])->with('success', 'Reversão oficial concluída com idempotência; o original foi preservado.');
    }
}
