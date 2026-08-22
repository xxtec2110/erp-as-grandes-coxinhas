<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvMappingRequest;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\PdvConnection;
use App\Models\PdvInboundEvent;
use App\Models\PdvIntegrationEvent;
use App\Models\PdvLocationMapping;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Product;
use App\Pdv\GrandChefRequestException;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use App\Services\AuthorizationService;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvConnectionService;
use App\Services\PdvConnectionTestService;
use App\Services\PdvOrderStagingService;
use App\Services\PdvSaleImportService;
use App\Services\PdvSyncService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PdvIntegrationController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, PdvConnectionService $connections): View
    {
        $locations = $authorization->accessibleLocations($request->user())->load(['pdvConnections' => fn ($query) => $query->where('provider', 'grandchef')->withCount([
            'inboundEvents as pending_count' => fn ($query) => $query->whereIn('status', ['received', 'waiting_mapping']),
            'inboundEvents as failed_count' => fn ($query) => $query->where('status', 'failed'),
        ])]);
        $legacyConnections = $request->user()->is_super_admin
            ? PdvConnection::query()->where('provider', 'grandchef')->whereNull('location_id')->get()
            : collect();
        $credentialConfigured = $locations->flatMap->pdvConnections
            ->merge($legacyConnections)
            ->mapWithKeys(fn (PdvConnection $connection): array => [$connection->id => $connections->credentialConfigured($connection)]);

        return view('pdv.index', compact('locations', 'legacyConnections', 'credentialConfigured'));
    }

    public function mappings(Request $request, PdvConnection $connection, PdvConnectionAccessService $access): View
    {
        $location = $access->assertOperationalScope($connection);
        $access->authorizeConnection($request->user(), $connection);

        return view('pdv.mappings', ['connection' => $connection, 'locations' => PdvLocationMapping::query()->whereBelongsTo($connection, 'connection')->with('location')->get(), 'products' => PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->with('product')->get(), 'payments' => PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->with(['acquirer', 'cardBrand'])->get(), 'erpLocations' => collect([$location]), 'erpProducts' => Product::query()->with('category')->orderBy('name')->get(), 'acquirers' => Acquirer::query()->where('active', true)->orderBy('name')->get(), 'cardBrands' => CardBrand::query()->where('active', true)->orderBy('name')->get()]);
    }

    public function updateMapping(PdvMappingRequest $request, PdvConnection $connection, PdvConnectionAccessService $access): RedirectResponse
    {
        $connectionLocation = $access->assertOperationalScope($connection);
        $access->authorizeConnection($request->user(), $connection);
        $d = $request->validated();
        $model = match ($d['mapping_type']) {
            'location' => PdvLocationMapping::class,
            'product' => PdvProductMapping::class,
            'payment' => PdvPaymentMethodMapping::class,
        };
        $mapping = $model::query()->where('pdv_connection_id', $connection->id)->findOrFail($d['mapping_id']);
        $values = ['status' => 'confirmed'];
        if ($d['mapping_type'] === 'location') {
            abort_unless((int) $d['target_id'] === $connectionLocation->id, 422, 'O mapeamento de unidade precisa usar a unidade da conexão.');
            $values['location_id'] = $d['target_id'];
        } elseif ($d['mapping_type'] === 'product') {
            abort_unless(Product::query()->whereKey($d['target_id'])->exists(), 422, 'O produto selecionado não existe.');
            $values['product_id'] = $d['target_id'];
            $values['match_source'] = 'admin';
        } else {
            $values = array_merge($values, ['payment_method' => $d['payment_method'], 'acquirer_id' => $d['acquirer_id'], 'card_brand_id' => $d['card_brand_id']]);
        }
        $mapping->update($values);

        return back()->with('success', 'Mapeamento atualizado.');
    }

    public function test(Request $request, PdvConnection $connection, PdvConnectionTestService $tests): RedirectResponse
    {
        try {
            $tests->test($connection, $request->user());

            return back()->with('success', 'Conexão GrandChef validada com resposta GraphQL real.');
        } catch (DomainException|GrandChefRequestException|IntegrationNotConfiguredException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function sync(PdvConnection $connection, PdvSyncService $sync, Request $request): RedirectResponse
    {
        try {
            $result = $sync->sync($connection, $request->user());

            return back()->with('success', "Sincronização concluída: {$result['staged']} pedido(s) no staging e {$result['imported']} venda(s) legada(s).");
        } catch (DomainException|GrandChefRequestException|IntegrationNotConfiguredException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function events(Request $request, PdvConnection $connection, PdvConnectionAccessService $access): View
    {
        $access->authorizeConnection($request->user(), $connection);

        return view('pdv.events', ['connection' => $connection, 'events' => PdvIntegrationEvent::query()->where('pdv_connection_id', $connection->id)->latest()->paginate(30)]);
    }

    public function reprocess(PdvInboundEvent $event, PdvProviderManager $providers, PdvSaleImportService $imports, PdvOrderStagingService $staging, Request $request, PdvConnectionAccessService $access): RedirectResponse
    {
        $access->authorizeConnection($request->user(), $event->connection);
        try {
            $sale = $providers->for($event->connection)->fetchSale($event->connection, (string) $event->external_sale_id);
            if (! $sale) {
                return back()->with('success', 'Venda externa não encontrada.');
            }
            if ($event->connection->provider === 'grandchef') {
                $staging->stage($event->connection, $sale);
                $event->update(['status' => 'received', 'error_code' => null, 'error_message' => null]);
            } else {
                $imports->import($event->connection, $sale, $request->user(), $event);
            }

            return back()->with('success', $event->connection->provider === 'grandchef'
                ? 'Evento reprocessado no staging; nenhuma venda ou baixa de estoque foi criada.'
                : 'Evento reprocessado com idempotência.');
        } catch (GrandChefRequestException|IntegrationNotConfiguredException|DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
