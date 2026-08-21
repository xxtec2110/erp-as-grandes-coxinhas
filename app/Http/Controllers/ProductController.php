<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdvProductOnboardingRequest;
use App\Http\Requests\ProductRequest;
use App\Models\PdvConnection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\PdvConnectionAccessService;
use App\Services\PdvProductOnboardingService;
use App\Services\ProductCatalogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ProductCatalogService $catalog, private PdvProductOnboardingService $onboarding, private PdvConnectionAccessService $connectionAccess) {}

    public function index(): View
    {
        return view('products.index', [
            'products' => Product::query()->with(['aliases', 'currentPrice', 'category', 'recipe'])
                ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sort_order')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(PdvProductOnboardingRequest $request): View
    {
        $context = null;
        if ($request->filled('pdv_connection_id')) {
            $connection = PdvConnection::query()->with('location')->findOrFail($request->integer('pdv_connection_id'));
            $this->connectionAccess->authorizeConnection($request->user(), $connection);
            $context = $this->onboarding->context($connection, (string) $request->validated('external_product_id'), CarbonImmutable::parse((string) $request->validated('onboarding_from')), CarbonImmutable::parse((string) $request->validated('onboarding_to')));
        }

        return view('products.create', ['categories' => ProductCategory::query()->where('active', true)->orderBy('name')->get(), 'onboarding' => $context]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $data['aliases'] ?? [];
        $context = null;
        if (! empty($data['pdv_connection_id'])) {
            $connection = PdvConnection::query()->with('location')->findOrFail($data['pdv_connection_id']);
            $this->connectionAccess->authorizeConnection($request->user(), $connection);
            $context = $this->onboarding->context($connection, (string) $data['external_product_id'], CarbonImmutable::parse((string) $data['onboarding_from']), CarbonImmutable::parse((string) $data['onboarding_to']));
            $this->onboarding->assertCategoryAllowed($context, isset($data['product_category_id']) ? (int) $data['product_category_id'] : null);
        }
        unset($data['aliases'], $data['pdv_connection_id'], $data['external_product_id'], $data['onboarding_from'], $data['onboarding_to']);
        $this->catalog->create($data, $aliases, $request->user());

        if ($context !== null) {
            return redirect()->route('pdv.mappings', [$context['connection'], 'from' => $context['from'], 'to' => $context['to'], 'status' => 'unmapped'])->with('success', 'Product oficial criado. O mapping continua pendente e exige confirmação manual.');
        }

        return redirect()->route('products.index')->with('success', 'Produto cadastrado com sucesso.');
    }

    public function edit(Product $product): View
    {
        $product->load(['aliases', 'currentPrice']);

        return view('products.edit', ['product' => $product, 'categories' => ProductCategory::query()->orderBy('name')->get()]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $data['aliases'] ?? $product->aliases()->pluck('name')->all();
        unset($data['aliases']);
        $this->catalog->update($product, $data, $aliases, $request->user());

        return redirect()->route('products.index')->with('success', 'Produto atualizado com sucesso.');
    }
}
