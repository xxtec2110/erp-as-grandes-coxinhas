<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvMappingRequest;
use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvInboundEvent;
use App\Models\PdvIntegrationEvent;
use App\Models\PdvLocationMapping;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use App\Services\PdvSaleImportService;
use App\Services\PdvSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PdvIntegrationController extends Controller
{
    public function index(): View
    {
        $connection = PdvConnection::query()->withCount(['inboundEvents as pending_count' => fn ($q) => $q->whereIn('status', ['received', 'waiting_mapping']), 'inboundEvents as failed_count' => fn ($q) => $q->where('status', 'failed')])->first();

        return view('pdv.index', ['connection' => $connection, 'events' => PdvInboundEvent::query()->with('connection')->latest()->paginate(20)]);
    }

    public function mappings(PdvConnection $connection): View
    {
        return view('pdv.mappings', ['connection' => $connection, 'locations' => PdvLocationMapping::query()->whereBelongsTo($connection, 'connection')->with('location')->get(), 'products' => PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->with('product')->get(), 'payments' => PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->get(), 'erpLocations' => Location::query()->orderBy('name')->get(), 'erpProducts' => Product::query()->orderBy('name')->get()]);
    }

    public function updateMapping(PdvMappingRequest $request, PdvConnection $connection): RedirectResponse
    {
        $d = $request->validated();
        $model = match ($d['mapping_type']) {
            'location' => PdvLocationMapping::class,'product' => PdvProductMapping::class,'payment' => PdvPaymentMethodMapping::class
        };
        $mapping = $model::query()->where('pdv_connection_id', $connection->id)->findOrFail($d['mapping_id']);
        $values = ['status' => 'confirmed'];
        if ($d['mapping_type'] === 'location') {
            $values['location_id'] = $d['target_id'];
        } elseif ($d['mapping_type'] === 'product') {
            $values['product_id'] = $d['target_id'];
            $values['match_source'] = 'admin';
        } else {
            $values = array_merge($values, ['payment_method' => $d['payment_method'], 'acquirer_id' => $d['acquirer_id'], 'card_brand_id' => $d['card_brand_id']]);
        }$mapping->update($values);

        return back()->with('success', 'Mapeamento atualizado.');
    }

    public function test(PdvConnection $connection, PdvProviderManager $providers): RedirectResponse
    {
        try {
            $providers->for($connection)->testConnection($connection);

            return back()->with('success', 'Conexão validada.');
        } catch (IntegrationNotConfiguredException $e) {
            return back()->with('success', $e->getMessage());
        }
    }

    public function sync(PdvConnection $connection, PdvSyncService $sync, Request $request): RedirectResponse
    {
        try {
            $result = $sync->sync($connection, $request->user());

            return back()->with('success', "Sincronização concluída: {$result['imported']} venda(s).");
        } catch (IntegrationNotConfiguredException $e) {
            return back()->with('success', $e->getMessage());
        }
    }

    public function events(PdvConnection $connection): View
    {
        return view('pdv.events', ['connection' => $connection, 'events' => PdvIntegrationEvent::query()->where('pdv_connection_id', $connection->id)->latest()->paginate(30)]);
    }

    public function reprocess(PdvInboundEvent $event, PdvProviderManager $providers, PdvSaleImportService $imports, Request $request): RedirectResponse
    {
        try {
            $sale = $providers->for($event->connection)->fetchSale($event->connection, (string) $event->external_sale_id);
            if (! $sale) {
                return back()->with('success', 'Venda externa não encontrada.');
            }$imports->import($event->connection, $sale, $request->user(), $event);

            return back()->with('success', 'Evento reprocessado com idempotência.');
        } catch (IntegrationNotConfiguredException $e) {
            return back()->with('success',$e->getMessage());
        }
    }
}
