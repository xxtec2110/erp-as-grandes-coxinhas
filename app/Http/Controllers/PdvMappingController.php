<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvMappingRequest;
use App\Http\Requests\PdvOrderPeriodRequest;
use App\Http\Requests\PdvPaymentMappingRequest;
use App\Http\Requests\PdvProductMappingBatchRequest;
use App\Http\Requests\PdvProductMappingRequest;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\PdvConnection;
use App\Models\PdvLocationMapping;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Services\AuthorizationService;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvMappingAuditService;
use App\Services\PdvMappingCatalogService;
use App\Services\PdvMappingService;
use App\Services\PdvOperationalReadinessService;
use App\Services\PdvOrderPreviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PdvMappingController extends Controller
{
    public function index(PdvOrderPeriodRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvMappingCatalogService $catalog, PdvOrderPreviewService $preview, PdvOperationalReadinessService $operational, PdvMappingAuditService $audits, AuthorizationService $authorization): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        [$from, $to, $fromDate, $toDate] = $this->period($request->validated(), $connection);
        $status = $request->string('status')->toString() === 'all' ? 'all' : 'unmapped';

        $catalogData = $catalog->forPeriod($connection, $fromDate, $toDate, $status);
        $readinessData = $preview->period($connection, $fromDate, $toDate);

        return view('pdv.mappings', [
            'connection' => $connection->load('location'),
            'catalog' => $catalogData,
            'readiness' => $readinessData,
            'operationalReadiness' => $operational->build($connection, $fromDate, $toDate, $catalogData, $readinessData),
            'mappingAudits' => $audits->history($connection),
            'canCreateProducts' => $authorization->allows($request->user(), 'products.create'),
            'erpProducts' => Product::query()->where('active', true)->with('category')->orderBy('name')->get(),
            'acquirers' => Acquirer::query()->where('active', true)->orderBy('name')->get(),
            'cardBrands' => CardBrand::query()->where('active', true)->orderBy('name')->get(),
            'from' => $from,
            'to' => $to,
            'status' => $status,
        ]);
    }

    public function updateProduct(PdvProductMappingRequest $request, PdvConnection $connection, string $externalProductId, PdvConnectionAccessService $access, PdvMappingService $mappings): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $mappings->confirmProduct($connection, $externalProductId, (int) $request->validated('product_id'), $request->user(), (string) $request->validated('idempotency_key'), $request->boolean('confirm_remap'), $request->validated('reason'));

        return $this->backToMappings($connection, $request->validated('from'), $request->validated('to'), 'Mapping de produto confirmado manualmente.');
    }

    public function updatePayment(PdvPaymentMappingRequest $request, PdvConnection $connection, string $externalFormId, PdvConnectionAccessService $access, PdvMappingService $mappings): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $mappings->confirmPayment($connection, $externalFormId, $request->validated(), $request->user());

        return $this->backToMappings($connection, $request->validated('from'), $request->validated('to'), 'Mapping financeiro confirmado manualmente.');
    }

    public function previewProductBatch(PdvProductMappingBatchRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvMappingCatalogService $catalog): View
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');
        $entries = $catalog->forPeriod($connection, CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone), 'all')['products']->keyBy('external_product_id');
        $rows = collect($request->selectedRows())->map(function (array $selection) use ($entries): array {
            $entry = $entries->get($selection['external_product_id']);
            if ($entry === null) {
                throw ValidationException::withMessages(['rows' => 'Um produto selecionado não pertence ao período e à conexão informados.']);
            }
            $product = Product::query()->whereKey($selection['product_id'])->where('active', true)->with('category')->firstOrFail();

            return compact('entry', 'product', 'selection');
        })->values();

        return view('pdv.mapping-batch-preview', compact('connection', 'rows', 'from', 'to'));
    }

    public function confirmProductBatch(PdvProductMappingBatchRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvMappingService $mappings): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $connection);
        $access->assertOperationalScope($connection);
        abort_unless($request->boolean('confirmed'), 422, 'A confirmação explícita do lote é obrigatória.');
        $rows = $request->selectedRows();
        $mappings->confirmProducts($connection, $rows, $request->user(), (string) $request->validated('idempotency_key'), $request->validated('reason'));

        return $this->backToMappings($connection, $request->validated('from'), $request->validated('to'), count($rows).' mapping(s) de produto confirmado(s) manualmente.');
    }

    public function legacyUpdate(PdvMappingRequest $request, PdvConnection $connection, PdvConnectionAccessService $access, PdvMappingService $mappings): RedirectResponse
    {
        $location = $access->assertOperationalScope($connection);
        $access->authorizeConnection($request->user(), $connection);
        $data = $request->validated();
        if ($data['mapping_type'] === 'product') {
            $mapping = PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->findOrFail($data['mapping_id']);
            $mappings->confirmProduct($connection, $mapping->external_product_id, (int) $data['target_id'], $request->user(), (string) $data['idempotency_key'], (bool) ($data['confirm_remap'] ?? false), $data['reason'] ?? null);
        } elseif ($data['mapping_type'] === 'payment') {
            $mapping = PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->findOrFail($data['mapping_id']);
            $mappings->confirmPayment($connection, $mapping->external_method_code, $data, $request->user());
        } else {
            abort_unless((int) $data['target_id'] === $location->id, 422, 'O mapeamento de unidade precisa usar a unidade da conexão.');
            DB::transaction(function () use ($connection, $data): void {
                PdvLocationMapping::query()->whereBelongsTo($connection, 'connection')->lockForUpdate()->findOrFail($data['mapping_id'])->update([
                    'location_id' => $data['target_id'],
                    'status' => 'confirmed',
                ]);
            });
        }

        return back()->with('success', 'Mapeamento atualizado.');
    }

    /** @param array<string,mixed> $validated @return array{0:string,1:string,2:CarbonImmutable,3:CarbonImmutable} */
    private function period(array $validated, PdvConnection $connection): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $latest = $connection->orders()->whereNotNull('external_completed_at')->max('external_completed_at');
        $default = $latest === null ? now($timezone)->toDateString() : CarbonImmutable::parse($latest)->setTimezone($timezone)->toDateString();
        $from = (string) ($validated['from'] ?? $default);
        $to = (string) ($validated['to'] ?? $from);

        return [$from, $to, CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone)];
    }

    private function backToMappings(PdvConnection $connection, string $from, string $to, string $message): RedirectResponse
    {
        return redirect()->route('pdv.mappings', [$connection, 'from' => $from, 'to' => $to, 'status' => 'unmapped'])->with('success', $message);
    }
}
