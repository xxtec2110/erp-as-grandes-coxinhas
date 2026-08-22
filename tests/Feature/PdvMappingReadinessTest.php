<?php

namespace Tests\Feature;

use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Models\Acquirer;
use App\Models\CardBrand;
use App\Models\CatalogAdminAudit;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\PaymentFee;
use App\Models\PdvConnection;
use App\Models\PdvOrder;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPrice;
use App\Models\ProductSale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PdvExternalProductSuggestionService;
use App\Services\PdvGoLiveService;
use App\Services\PdvMappingCatalogService;
use App\Services\PdvMappingService;
use App\Services\PdvOrderReconciliationService;
use App\Services\PdvPaymentCompatibilityService;
use App\Services\PdvProductBatchOnboardingService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PdvMappingReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $ibira;

    private Location $catanduva;

    private PdvConnection $ibiraConnection;

    private PdvConnection $catanduvaConnection;

    private ProductCategory $coxinhas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->ibira = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $this->catanduva = Location::query()->create(['name' => 'Unidade Catanduva', 'type' => 'store', 'active' => true]);
        $this->ibiraConnection = PdvConnection::query()->firstOrFail();
        $this->ibiraConnection->update(['location_id' => $this->ibira->id, 'name' => 'GrandChef Ibirá', 'enabled' => true, 'status' => 'healthy', 'operational_start_at' => '2026-08-20 00:00:00-03:00']);
        $this->catanduvaConnection = PdvConnection::query()->create(['location_id' => $this->catanduva->id, 'provider' => 'grandchef', 'name' => 'GrandChef Catanduva', 'enabled' => false, 'status' => 'not_configured', 'operational_start_at' => '2026-08-20 00:00:00-03:00']);
        $this->coxinhas = ProductCategory::query()->create(['name' => 'Coxinhas', 'active' => true]);
    }

    public function test_external_product_catalog_aggregates_real_dimensions_and_is_connection_scoped(): void
    {
        $frango = $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [
            $this->item('I-1', 'P-FRANGO', '33', '01- COXINHA DE FRANGO COM CATUPIRY', '2', '32'),
            $this->item('I-2', 'P-AGUA', '70', '01- ÁGUA DE COCO', '1', '12'),
        ], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '44')], '44');
        $this->order($this->ibiraConnection, 'IB-2', [$this->item('I-1', 'P-FRANGO', '33', '01- COXINHA DE FRANGO COM CATUPIRY', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $this->order($this->catanduvaConnection, 'CAT-1', [$this->item('I-1', 'P-FRANGO', '999', 'Outra descrição', '9', '90')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '90')], '90');

        $catalog = $this->catalog();
        $row = $catalog['products']->firstWhere('external_product_id', 'P-FRANGO');
        $this->assertSame(2, $catalog['summary']['products_distinct']);
        $this->assertSame('3', $row['quantity_total']);
        $this->assertSame('48', $row['value_total']);
        $this->assertSame(2, $row['order_count']);
        $this->assertSame($frango->id, $row['suggestion']['product']->id);
        $this->assertSame('exact', $row['suggestion']['type']);
        $this->assertSame(1, $catalog['summary']['products_without_candidate']);
        $this->assertDatabaseCount('pdv_product_mappings', 0);
    }

    public function test_product_suggestions_handle_accents_alias_similarity_and_category_guard_without_persistence(): void
    {
        $frango = $this->product('Frango, milho, muçarela e catupiry');
        $costela = $this->product('Costela com queijo');
        $costela->aliases()->create(['name' => 'Costela premium', 'normalized_name' => 'costela premium']);
        $service = app(PdvExternalProductSuggestionService::class);
        $products = Product::query()->with(['category', 'aliases'])->get();

        $this->assertSame('exact', $service->suggest('07- COXINHA DE FRANGO, MILHO, MUSSARELA E CATUPIRY', $products)['type']);
        $this->assertSame($frango->id, $service->suggest('07- COXINHA DE FRANGO, MILHO, MUSSARELA E CATUPIRY', $products)['product']->id);
        $this->assertSame('alias', $service->suggest('Coxinha de costela premium', $products)['type']);
        $this->assertSame('similar', $service->suggest('Coxinha frango milho e mucarela', $products)['type']);
        $this->assertSame('none', $service->suggest('Coca-Cola lata 350ml', $products)['type']);
        $this->assertDatabaseCount('pdv_product_mappings', 0);
    }

    public function test_inactive_product_is_never_suggested_or_accepted(): void
    {
        $inactive = $this->product('Frango com catupiry', false);
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $this->assertSame('none', $this->catalog()['products']->first()['suggestion']['type']);

        try {
            $this->confirmProduct($this->ibiraConnection, 'P1', $inactive->id);
            $this->fail('Produto inativo não deveria ser aceito.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_id', $exception->errors());
        }
        $this->assertDatabaseCount('pdv_product_mappings', 0);
    }

    public function test_manual_product_mapping_is_transactional_idempotent_and_requires_explicit_remap(): void
    {
        $firstProduct = $this->product('Frango com catupiry');
        $secondProduct = $this->product('Costela com queijo');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $productsBefore = Product::query()->count();
        $first = $this->confirmProduct($this->ibiraConnection, 'P1', $firstProduct->id);
        $same = $this->confirmProduct($this->ibiraConnection, 'P1', $firstProduct->id);
        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('pdv_product_mappings', 1);
        $this->assertSame($productsBefore, Product::query()->count());

        try {
            $this->confirmProduct($this->ibiraConnection, 'P1', $secondProduct->id);
            $this->fail('Remap silencioso não deveria ser aceito.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_remap', $exception->errors());
        }
        $updated = $this->confirmProduct($this->ibiraConnection, 'P1', $secondProduct->id, true);
        $this->assertSame($secondProduct->id, $updated->product_id);
        $this->assertDatabaseCount('pdv_product_mappings', 1);
    }

    public function test_product_mapping_cannot_cross_connection_scope(): void
    {
        $product = $this->product('Frango com catupiry');
        $this->order($this->catanduvaConnection, 'CAT-1', [$this->item('I-1', 'SHARED', '1', 'Coxinha de frango', '1', '10')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '10')], '10');
        $this->expectException(ValidationException::class);
        $this->confirmProduct($this->ibiraConnection, 'SHARED', $product->id);
    }

    public function test_batch_preview_never_saves_and_confirmation_gate_is_required(): void
    {
        $product = $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $payload = ['from' => '2026-08-20', 'to' => '2026-08-20', 'idempotency_key' => (string) Str::uuid(), 'rows' => [['selected' => 1, 'external_product_id' => 'P1', 'product_id' => $product->id, 'confirm_remap' => 0]]];

        $this->actingAs($this->admin)->post(route('pdv.mappings.products.batch.preview', $this->ibiraConnection), $payload)
            ->assertOk()->assertSee('Nada foi gravado')->assertSee('Frango com catupiry');
        $this->assertDatabaseCount('pdv_product_mappings', 0);
        $this->actingAs($this->admin)->post(route('pdv.mappings.products.batch.confirm', $this->ibiraConnection), $payload)->assertStatus(422);
        $this->assertDatabaseCount('pdv_product_mappings', 0);

        $this->actingAs($this->admin)->post(route('pdv.mappings.products.batch.confirm', $this->ibiraConnection), [...$payload, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseHas('pdv_product_mappings', ['pdv_connection_id' => $this->ibiraConnection->id, 'external_product_id' => 'P1', 'product_id' => $product->id, 'status' => 'confirmed']);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_individual_mapping_routes_use_the_manual_services_without_operational_writes(): void
    {
        $product = $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $period = ['from' => '2026-08-20', 'to' => '2026-08-20', 'idempotency_key' => (string) Str::uuid()];

        $this->actingAs($this->admin)->put(route('pdv.mappings.products.update', [$this->ibiraConnection, 'P1']), [...$period, 'product_id' => $product->id])->assertRedirect();
        $this->actingAs($this->admin)->put(route('pdv.mappings.payments.update', [$this->ibiraConnection, '99900']), [...$period, 'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash'])->assertRedirect();
        $this->assertDatabaseHas('pdv_product_mappings', ['external_product_id' => 'P1', 'product_id' => $product->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('pdv_payment_method_mappings', ['external_method_code' => '99900', 'payment_method' => 'cash', 'status' => 'confirmed']);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_payment_catalog_aggregates_split_payment_and_supports_pix_as_its_own_method(): void
    {
        $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '80')], [
            $this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '40'),
            $this->payment('PAY-2', '101265', 'Pix', 'pix', '40'),
        ], '80');
        $catalog = $this->catalog();

        $this->assertSame(2, $catalog['summary']['payments_distinct']);
        $this->assertSame(2, $catalog['summary']['payments_unmapped']);
        $this->assertSame(0, $catalog['summary']['payments_unsupported']);
        $this->assertSame(80, $catalog['payments']->sum(fn (array $row): string => $row['amount_total']));
        $this->assertTrue($catalog['payments']->firstWhere('external_form_id', '101265')['compatibility']['supported']);
        $this->assertSame('pix', $catalog['payments']->firstWhere('external_form_id', '101265')['compatibility']['method']);
        $this->assertDatabaseCount('pdv_payment_method_mappings', 0);
    }

    public function test_cash_and_pix_mappings_are_supported_without_card_configuration_or_financial_fiction(): void
    {
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Produto', '1', '20')], [
            $this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '10'),
            $this->payment('PAY-2', '101265', 'Pix', 'pix', '10'),
        ], '20');
        $compatibility = app(PdvPaymentCompatibilityService::class);
        $this->assertSame('cash', $compatibility->forExternal('Dinheiro', 'dinheiro')['method']);
        $this->assertSame('debit', $compatibility->forExternal('Débito', 'debito')['method']);
        $this->assertSame('credit', $compatibility->forExternal('Crédito', 'credito')['method']);
        $this->assertSame('pix', $compatibility->forExternal('Pix', 'pix')['method']);
        $cash = $this->confirmPayment($this->ibiraConnection, '99900', ['payment_method' => 'cash']);
        $this->assertNull($cash->acquirer_id);
        $this->assertNull($cash->card_brand_id);
        $pix = $this->confirmPayment($this->ibiraConnection, '101265', ['payment_method' => 'pix']);
        $this->assertNull($pix->acquirer_id);
        $this->assertNull($pix->card_brand_id);
        $this->assertDatabaseCount('pdv_payment_method_mappings', 2);
    }

    public function test_debit_mapping_requires_active_catalogs_and_readiness_requires_current_rate(): void
    {
        $product = $this->product('Frango com catupiry');
        $order = $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '2', '20')], [$this->payment('PAY-1', '99902', 'Débito', 'debito', '20')], '20');
        $this->confirmProduct($this->ibiraConnection, 'P1', $product->id);
        $acquirer = Acquirer::query()->create(['name' => 'Adquirente', 'active' => true]);
        $brand = CardBrand::query()->create(['name' => 'Bandeira', 'active' => true]);
        $this->stock($product, '10');

        PdvPaymentMethodMapping::query()->create(['pdv_connection_id' => $this->ibiraConnection->id, 'external_method_code' => '99902', 'external_name' => 'Débito', 'payment_method' => 'debit', 'acquirer_id' => $acquirer->id, 'card_brand_id' => $brand->id, 'status' => 'confirmed']);

        $withoutRate = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertContains('payment_rate_missing', collect($withoutRate['blockers'])->pluck('code'));
        PaymentFee::query()->create(['acquirer_id' => $acquirer->id, 'card_brand_id' => $brand->id, 'payment_method' => 'debit', 'fee_percentage' => '1.500000', 'fixed_fee' => '0', 'effective_from' => '2026-08-01', 'is_current' => true, 'active' => true, 'source' => 'test']);
        $ready = app(PdvOrderReconciliationService::class)->reconcile($order);
        $this->assertTrue($ready['ready_for_import']);
    }

    public function test_readiness_is_atomic_for_partial_product_and_split_payment_mappings(): void
    {
        $product = $this->product('Frango com catupiry');
        $order = $this->order($this->ibiraConnection, 'IB-1', [
            $this->item('I-1', 'P1', '33', 'Coxinha de frango', '1', '10'),
            $this->item('I-2', 'P2', '36', 'Coxinha de costela', '1', '10'),
        ], [
            $this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '10'),
            $this->payment('PAY-2', '101265', 'Pix', 'pix', '10'),
        ], '20');
        $this->confirmProduct($this->ibiraConnection, 'P1', $product->id);
        $this->confirmPayment($this->ibiraConnection, '99900', ['payment_method' => 'cash']);
        $this->stock($product, '10');
        $result = app(PdvOrderReconciliationService::class)->reconcile($order);
        $codes = collect($result['blockers'])->pluck('code');

        $this->assertFalse($result['ready_for_import']);
        $this->assertContains('product_mapping_missing', $codes);
        $this->assertContains('payment_mapping_missing', $codes);
        $this->assertDatabaseCount('product_sales', 0);
    }

    public function test_stock_need_is_consolidated_for_repeated_lines_of_the_same_product(): void
    {
        $product = $this->product('Frango com catupiry');
        $order = $this->order($this->ibiraConnection, 'IB-1', [
            $this->item('I-1', 'P1', '33', 'Coxinha de frango', '1', '10'),
            $this->item('I-2', 'P1', '33', 'Coxinha de frango', '2', '20'),
        ], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '30')], '30');
        $this->confirmProduct($this->ibiraConnection, 'P1', $product->id);
        $this->confirmPayment($this->ibiraConnection, '99900', ['payment_method' => 'cash']);
        $this->stock($product, '2');
        $result = app(PdvOrderReconciliationService::class)->reconcile($order);

        $this->assertSame(1, count($result['stock_status']['products']));
        $this->assertSame('3.000000', $result['stock_status']['products'][0]['required']);
        $this->assertContains('stock_insufficient', collect($result['blockers'])->pluck('code'));
    }

    public function test_mapping_pages_enforce_permission_location_scope_and_never_render_credentials(): void
    {
        $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $this->ibiraConnection->update(['encrypted_credentials' => ['bearer_token' => 'secret-bearer', 'device_token' => 'secret-device']]);
        $this->get(route('pdv.mappings', $this->ibiraConnection))->assertRedirect(route('login'));
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($restricted)->get(route('pdv.mappings', $this->ibiraConnection))->assertForbidden();
        $restricted->locations()->attach($this->ibira);
        $this->actingAs($restricted)->get(route('pdv.mappings', $this->ibiraConnection))
            ->assertOk()->assertSee('Mapeamentos GrandChef')->assertDontSee('secret-bearer')->assertDontSee('secret-device');
        $this->actingAs($restricted)->get(route('pdv.mappings', $this->catanduvaConnection))->assertForbidden();
    }

    public function test_read_only_mapping_and_readiness_views_have_no_operational_side_effects(): void
    {
        $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $before = $this->integrityCounts();
        $this->actingAs($this->admin)->get(route('pdv.mappings', [$this->ibiraConnection, 'from' => '2026-08-20', 'to' => '2026-08-20']))->assertOk();
        $this->assertSame($before, $this->integrityCounts());
    }

    public function test_mapping_audit_preserves_before_after_actor_connection_and_sanitized_history(): void
    {
        $first = $this->product('Frango com catupiry');
        $second = $this->product('Costela com queijo');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');

        $this->confirmProduct($this->ibiraConnection, 'P1', $first->id);
        $this->confirmProduct($this->ibiraConnection, 'P1', $second->id, true);

        $audits = CatalogAdminAudit::query()->where('status', 'success')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($this->admin->id, $audits->last()->user_id);
        $this->assertSame($this->ibiraConnection->id, $audits->last()->context['pdv_connection_id']);
        $this->assertSame('product', $audits->last()->context['mapping_type']);
        $this->assertSame('change', $audits->last()->context['action']);
        $this->assertSame($first->id, $audits->last()->before_values['product_id']);
        $this->assertSame($second->id, $audits->last()->after_values['product_id']);
        $serialized = mb_strtolower(json_encode($audits->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('bearer_token', $serialized);
        $this->assertStringNotContainsString('device_token', $serialized);
        $this->assertStringNotContainsString('authorization', $serialized);
    }

    public function test_human_onboarding_shows_observed_prices_blocks_missing_category_and_uses_official_product_flow_only_after_submit(): void
    {
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'BEB-1', '70', '01- ÁGUA DE COCO', '1', '12')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '12')], '12');
        $this->order($this->ibiraConnection, 'IB-2', [$this->item('I-1', 'BEB-1', '70', '01- ÁGUA DE COCO', '1', '14')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '14')], '14');
        $url = route('products.create', ['pdv_connection_id' => $this->ibiraConnection->id, 'external_product_id' => 'BEB-1', 'onboarding_from' => '2026-08-20', 'onboarding_to' => '2026-08-20']);
        $productsBefore = Product::query()->count();

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Onboarding humano')->assertSee('Categoria oficial para bebidas precisa ser definida')->assertSee('12,00')->assertSee('14,00');
        $this->assertSame($productsBefore, Product::query()->count());
        $payload = ['name' => 'Água de Coco', 'product_category_id' => $this->coxinhas->id, 'stock_unit' => 'un', 'selling_price' => '14.00', 'active' => 1, 'pdv_connection_id' => $this->ibiraConnection->id, 'external_product_id' => 'BEB-1', 'onboarding_from' => '2026-08-20', 'onboarding_to' => '2026-08-20'];
        $this->actingAs($this->admin)->post(route('products.store'), $payload)->assertSessionHasErrors('product_category_id');
        $this->assertSame($productsBefore, Product::query()->count());

        $beverages = ProductCategory::query()->create(['name' => 'Bebidas', 'active' => true]);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Bebidas')->assertDontSee('Categoria oficial para bebidas precisa ser definida');
        $this->actingAs($this->admin)->post(route('products.store'), [...$payload, 'product_category_id' => $beverages->id])->assertRedirect(route('pdv.mappings', [$this->ibiraConnection, 'from' => '2026-08-20', 'to' => '2026-08-20', 'status' => 'unmapped']));
        $created = Product::query()->where('name', 'Água de Coco')->firstOrFail();
        $this->assertSame($beverages->id, $created->product_category_id);
        $this->assertDatabaseHas('product_prices', ['product_id' => $created->id, 'price' => '14.0000', 'is_current' => true]);
        $this->assertDatabaseCount('pdv_product_mappings', 0);
        $this->assertSame(1, ProductPrice::query()->whereBelongsTo($created)->count());
    }

    public function test_readiness_wizard_and_simulations_are_derived_without_persistence(): void
    {
        $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '101265', 'Pix', 'pix', '16')], '16');
        $before = $this->integrityCounts();

        $this->actingAs($this->admin)->get(route('pdv.mappings', [$this->ibiraConnection, 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertOk()->assertSee('Etapa 1')->assertSee('Próximas ações para liberar a operação')->assertSee('Pedidos em que Pix deixa de ser incompatível')->assertSee('Pix')->assertDontSee('Importar venda');
        $this->assertSame($before, $this->integrityCounts());
    }

    public function test_go_live_dashboard_is_derived_read_only_scoped_and_does_not_treat_suggestions_as_stock(): void
    {
        $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [
            $this->item('I-1', 'P-FRANGO', '33', 'Coxinha de frango com catupiry', '1', '16'),
            $this->item('I-2', 'P-AGUA', '70', 'Água de coco', '1', '12'),
        ], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '28')], '28');
        $this->ibiraConnection->update(['encrypted_credentials' => ['bearer_token' => 'never-render-bearer', 'device_token' => 'never-render-device']]);
        $before = $this->integrityCounts();
        $url = route('pdv.go-live', [$this->ibiraConnection, 'from' => '2026-08-20', 'to' => '2026-08-20']);

        $this->get($url)->assertRedirect(route('login'));
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($restricted)->get($url)->assertForbidden();
        $restricted->locations()->attach($this->ibira);
        $this->actingAs($restricted)->get($url)->assertOk();
        $this->actingAs($restricted)->get(route('pdv.go-live', [$this->catanduvaConnection, 'from' => '2026-08-20', 'to' => '2026-08-20']))->assertForbidden();
        $response = $this->actingAs($this->admin)->get($url)
            ->assertOk()->assertSee('Preparar GrandChef para operação')->assertSee('can_enable_import=false')
            ->assertSee('Água de coco')->assertSee('Nenhuma linha vem selecionada')
            ->assertDontSee('never-render-bearer')->assertDontSee('never-render-device');
        $this->assertStringNotContainsString('name="rows[1][selected]" value="1" checked', $response->getContent());
        $readModel = app(PdvGoLiveService::class)->build($this->ibiraConnection, CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-08-20'));
        $this->assertSame(2, $readModel['catalog']['summary']['products_distinct']);
        $this->assertSame(1, $readModel['catalog']['summary']['products_exact']);
        $this->assertSame(1, $readModel['catalog']['summary']['products_without_candidate']);
        $this->assertCount(0, $readModel['catalog']['stock_preview']);
        $this->assertFalse($readModel['can_enable_import']);
        $this->assertSame($before, $this->integrityCounts());
    }

    public function test_batch_product_onboarding_requires_preview_and_explicit_confirmation_without_auto_mapping(): void
    {
        $beverages = ProductCategory::query()->create(['name' => 'Bebidas', 'active' => true]);
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'BEB-1', '70', '01- ÁGUA DE COCO', '2', '24')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '24')], '24');
        $payload = ['from' => '2026-08-20', 'to' => '2026-08-20', 'rows' => [[
            'selected' => 1,
            'external_product_id' => 'BEB-1',
            'name' => 'Água de Coco',
            'product_category_id' => $beverages->id,
            'selling_price' => '12.00',
            'active' => 1,
        ]]];

        $this->actingAs($this->admin)->post(route('pdv.go-live.products.preview', $this->ibiraConnection), $payload)
            ->assertOk()->assertSee('Nada foi gravado')->assertSee('Água de Coco')->assertSee('CRIAR PRODUTOS');
        $this->assertDatabaseMissing('products', ['name' => 'Água de Coco']);

        $preview = app(PdvProductBatchOnboardingService::class)->preview(
            $this->ibiraConnection,
            $this->admin,
            CarbonImmutable::parse('2026-08-20'),
            CarbonImmutable::parse('2026-08-20'),
            $payload['rows'],
        );
        $this->actingAs($this->admin)->post(route('pdv.go-live.products.confirm', $this->ibiraConnection), [
            'preview_token' => $preview['token'], 'confirmed' => 1, 'confirmation_text' => 'INCORRETO',
        ])->assertSessionHasErrors('confirmation_text');
        $this->assertDatabaseMissing('products', ['name' => 'Água de Coco']);

        $this->actingAs($this->admin)->post(route('pdv.go-live.products.confirm', $this->ibiraConnection), [
            'preview_token' => $preview['token'], 'confirmed' => 1, 'confirmation_text' => 'CRIAR PRODUTOS',
        ])->assertRedirect(route('pdv.go-live', $this->ibiraConnection));
        $product = Product::query()->where('name', 'Água de Coco')->firstOrFail();
        $this->assertSame($beverages->id, $product->product_category_id);
        $this->assertDatabaseHas('product_prices', ['product_id' => $product->id, 'price' => '12.0000', 'is_current' => true, 'source' => 'pdv_onboarding_batch']);
        $this->assertDatabaseCount('pdv_product_mappings', 0);
        $this->assertDatabaseCount('product_sales', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_go_live_gate_becomes_ready_only_after_confirmed_mappings_rates_and_stock(): void
    {
        $product = $this->product('Frango com catupiry');
        $this->order($this->ibiraConnection, 'IB-1', [$this->item('I-1', 'P1', '33', 'Coxinha de frango com catupiry', '1', '16')], [$this->payment('PAY-1', '99900', 'Dinheiro', 'dinheiro', '16')], '16');
        $this->confirmProduct($this->ibiraConnection, 'P1', $product->id);
        $this->confirmPayment($this->ibiraConnection, '99900', ['payment_method' => 'cash']);
        $this->stock($product, '10');

        $readModel = app(PdvGoLiveService::class)->build($this->ibiraConnection, CarbonImmutable::parse('2026-08-20'), CarbonImmutable::parse('2026-08-20'));
        $this->assertTrue($readModel['can_enable_import']);
        $this->assertFalse($readModel['import_enabled']);
        $this->assertFalse($readModel['can_execute_import']);
        $this->assertSame(1, $readModel['dry_run_summary']['ready']);
        $this->assertSame(0, $readModel['dry_run_summary']['blocked']);
    }

    /** @return array<string,mixed> */
    private function catalog(): array
    {
        return app(PdvMappingCatalogService::class)->forPeriod($this->ibiraConnection, CarbonImmutable::parse('2026-08-20', 'America/Sao_Paulo'), CarbonImmutable::parse('2026-08-20', 'America/Sao_Paulo'));
    }

    private function confirmProduct(PdvConnection $connection, string $externalProductId, int $productId, bool $confirmRemap = false): PdvProductMapping
    {
        return app(PdvMappingService::class)->confirmProduct($connection, $externalProductId, $productId, $this->admin, (string) Str::uuid(), $confirmRemap);
    }

    /** @param array<string,mixed> $data */
    private function confirmPayment(PdvConnection $connection, string $externalFormId, array $data): PdvPaymentMethodMapping
    {
        return app(PdvMappingService::class)->confirmPayment($connection, $externalFormId, [...$data, 'idempotency_key' => (string) Str::uuid()], $this->admin);
    }

    private function product(string $name, bool $active = true): Product
    {
        return Product::query()->create(['name' => $name, 'product_category_id' => $this->coxinhas->id, 'stock_unit' => 'un', 'active' => $active]);
    }

    /** @param array<int,array<string,string>> $items @param array<int,array<string,string>> $payments */
    private function order(PdvConnection $connection, string $externalId, array $items, array $payments, string $total): PdvOrder
    {
        $when = CarbonImmutable::parse('2026-08-20 12:00:00', 'America/Sao_Paulo');
        $order = PdvOrder::query()->create([
            'pdv_connection_id' => $connection->id, 'location_id' => $connection->location_id, 'external_order_id' => $externalId, 'external_code' => $externalId, 'external_status' => 'concluido', 'quantity' => collect($items)->sum(fn (array $item): string => $item['quantity']), 'subtotal' => $total, 'discount_total' => '0', 'total' => $total, 'paid_total' => $total, 'change_total' => '0', 'external_created_at' => $when, 'external_completed_at' => $when, 'external_updated_at' => $when, 'source_hash' => str_repeat('a', 64), 'latest_source_hash' => str_repeat('a', 64), 'processing_state' => PdvOrder::STATE_STAGED, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        foreach ($items as $item) {
            $order->items()->create([...$item, 'unit_price' => $item['total'], 'subtotal' => $item['total'], 'external_status' => 'concluido', 'cancelled' => false, 'present_in_latest' => true, 'source_hash' => str_repeat('b', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
        foreach ($payments as $payment) {
            $order->payments()->create([...$payment, 'external_total' => $payment['amount'], 'fees' => '0', 'external_status' => 'pago', 'present_in_latest' => true, 'source_hash' => str_repeat('c', 64), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }

        return $order->load(['connection', 'location', 'items', 'payments']);
    }

    /** @return array<string,string> */
    private function item(string $id, string $productId, string $code, string $description, string $quantity, string $total): array
    {
        return ['external_item_id' => $id, 'external_product_id' => $productId, 'external_product_code' => $code, 'description' => $description, 'quantity' => $quantity, 'total' => $total];
    }

    /** @return array<string,string> */
    private function payment(string $id, string $formId, string $description, string $type, string $amount): array
    {
        return ['external_payment_id' => $id, 'external_form_id' => $formId, 'external_form_description' => $description, 'external_type' => $type, 'amount' => $amount];
    }

    private function stock(Product $product, string $quantity): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $this->ibira->id, StockMovementType::OpeningBalance, $quantity, '2026-08-20', 'pdv-mapping-test-'.$product->id));
    }

    /** @return array<string,int> */
    private function integrityCounts(): array
    {
        return [
            'products' => Product::query()->count(),
            'product_mappings' => PdvProductMapping::query()->count(),
            'payment_mappings' => PdvPaymentMethodMapping::query()->count(),
            'product_sales' => ProductSale::query()->count(),
            'stock_movements' => StockMovement::query()->count(),
            'ingredient_stock_movements' => IngredientStockMovement::query()->count(),
        ];
    }
}
