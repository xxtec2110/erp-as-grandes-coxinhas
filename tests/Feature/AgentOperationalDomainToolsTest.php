<?php

namespace Tests\Feature;

use App\Agent\AgentConversationService;
use App\Agent\AgentMessage;
use App\Agent\AgentToolExecutor;
use App\Agent\AgentToolRegistry;
use App\Agent\ErpAgentService;
use App\Agent\PendingAgentActionService;
use App\Data\Stock\RecordStockMovementData;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Models\FinancialAccount;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\LossReason;
use App\Models\Payable;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\CreatePayableService;
use App\Services\IngredientPriceService;
use App\Services\IngredientStockService;
use App\Services\ProductPriceService;
use App\Services\ProductRecipeService;
use App\Services\RegisterPaymentService;
use App\Services\StockBalanceService;
use App\Services\StockMovementService;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOperationalDomainToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $factory;

    private Location $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        config()->set('ai.provider', 'fake');
        $this->factory = Location::query()->create(['name' => 'Fábrica Central', 'type' => Location::TYPE_PRODUCTION, 'active' => true]);
        $this->store = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => Location::TYPE_STORE, 'active' => true]);
        $this->admin = User::factory()->create(['name' => 'Admin Master', 'active' => true, 'default_location_id' => $this->factory->id]);
        UserExternalIdentity::query()->create([
            'user_id' => $this->admin->id,
            'channel' => 'local-test',
            'external_user_id' => 'operational-master',
            'status' => 'approved',
            'active' => true,
            'structured_commands_allowed' => true,
            'free_chat_allowed' => true,
            'voice_allowed' => true,
            'image_allowed' => true,
            'document_allowed' => true,
        ]);
    }

    public function test_registry_exposes_operational_read_and_write_contracts(): void
    {
        $tools = app(AgentToolRegistry::class)->all();
        foreach ([
            'products.catalog.query', 'ingredients.catalog.query', 'suppliers.catalog.query',
            'purchases.summary', 'purchases.receipts.receive', 'finance.payables.list',
            'finance.payments.record', 'production.orders.query', 'production.orders.complete_batch',
            'losses.query', 'losses.record', 'transfers.list', 'transfers.create',
            'transfers.dispatch', 'transfers.receive',
        ] as $name) {
            $this->assertArrayHasKey($name, $tools);
            $this->assertTrue(Permission::query()->where('name', $tools[$name]->permission)->exists(), $name);
            $this->assertIsArray($tools[$name]->inputSchema);
        }
        $this->assertArrayHasKey('paid_at', $tools['finance.payments.record']->inputSchema);
        $this->assertArrayHasKey('financial_account_id', $tools['finance.payments.record']->inputSchema);
        $this->assertArrayNotHasKey('received', $tools['purchases.documents.create']->inputSchema);
    }

    public function test_catalog_reads_return_only_safe_explicit_fields(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor Seguro', 'document_number' => 'segredo-fiscal', 'notes' => 'nota interna', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha', 'base_unit' => 'g', 'notes' => 'segredo interno', 'active' => true]);
        app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '8', 'effective_date' => now()->toDateString()]);
        $product = Product::query()->create(['name' => 'Coxinha Segura', 'stock_unit' => 'un', 'active' => true]);
        app(ProductPriceService::class)->record($product, '16', $this->admin);

        $executor = app(AgentToolExecutor::class);
        $products = $executor->execute('products.catalog.query', [], $this->admin);
        $ingredients = $executor->execute('ingredients.catalog.query', [], $this->admin);
        $suppliers = $executor->execute('suppliers.catalog.query', [], $this->admin);

        $this->assertEqualsCanonicalizing(['product_id', 'name', 'category', 'stock_unit', 'active', 'selling_price', 'price_effective_date', 'has_recipe'], array_keys($products['items'][0]));
        $this->assertArrayNotHasKey('notes', $ingredients['items'][0]);
        $this->assertArrayNotHasKey('document_number', $suppliers['items'][0]);
        $this->assertArrayNotHasKey('notes', $suppliers['items'][0]);
    }

    public function test_product_creation_uses_preview_cancellation_confirmation_and_message_idempotency(): void
    {
        $preview = $this->agent('product-preview', ['tool' => 'catalog.products.create', 'arguments' => ['name' => 'Coxinha Operacional', 'selling_price' => '19.90']]);
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseMissing('products', ['name' => 'Coxinha Operacional']);
        $this->answer('product-cancel', 'CANCELAR');
        $this->assertDatabaseMissing('products', ['name' => 'Coxinha Operacional']);

        $this->agent('product-create', ['tool' => 'catalog.products.create', 'arguments' => ['name' => 'Coxinha Operacional', 'selling_price' => '19.90']]);
        $confirmed = $this->answer('product-confirm', 'SIM');
        $replayed = $this->answer('product-confirm', 'SIM');
        $this->assertTrue($confirmed->success);
        $this->assertSame($confirmed->toArray(), $replayed->toArray());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_prices', 1);
    }

    public function test_exact_product_duplicate_is_blocked_and_similar_name_requires_explicit_clarification(): void
    {
        Product::query()->create(['name' => 'Coxinha de Frango', 'stock_unit' => 'un', 'active' => true]);
        $exact = $this->agent('product-exact', ['tool' => 'catalog.products.create', 'arguments' => ['name' => 'coxinha de frango', 'selling_price' => '20']]);
        $this->assertSame('product_already_exists', $exact->errorCode);
        $this->answer('product-exact-cancel', 'CANCELAR');

        $similar = $this->agent('product-similar', ['tool' => 'catalog.products.create', 'arguments' => ['name' => 'Coxinha de Frango Especial', 'selling_price' => '22']]);
        $this->assertStringContainsString('produto semelhante', mb_strtolower($similar->message));
        $blocked = $this->answer('product-similar-answer', 'SIM');
        $this->assertSame('similar_product_requires_clarification', $blocked->errorCode);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_product_price_preview_preserves_history_and_effective_date(): void
    {
        $product = Product::query()->create(['name' => 'Coxinha Preço', 'stock_unit' => 'un', 'active' => true]);
        app(ProductPriceService::class)->record($product, '18', $this->admin, 'web', 'price-old', '2026-08-01');
        $preview = $this->agent('price-preview', ['tool' => 'catalog.products.update_price', 'arguments' => ['product_name' => 'Coxinha Preço', 'selling_price' => '21.50', 'effective_date' => '2026-08-24']]);
        $this->assertStringContainsString('Preço atual: R$ 18,00', $preview->message);
        $this->assertSame('18.0000', $product->fresh()->currentPrice->price);
        $this->answer('price-confirm', 'CONFIRMAR');
        $this->assertSame('21.5000', $product->fresh()->currentPrice->price);
        $this->assertSame(2, $product->prices()->count());
        $this->assertDatabaseHas('product_prices', ['effective_date' => '2026-08-24 00:00:00']);
    }

    public function test_purchase_confirmation_never_receives_stock_and_partial_receipt_is_separate_and_idempotent(): void
    {
        [$supplier, $ingredient] = $this->pricedIngredient();
        $purchase = $this->confirmed('purchases.documents.create', [
            'location_id' => $this->factory->id,
            'supplier_id' => $supplier->id,
            'document_type' => 'invoice',
            'document_number' => 'NF-AGENT-1',
            'issue_date' => '2026-08-24',
            'total_amount' => '80',
            'items' => [['ingredient_id' => $ingredient->id, 'description' => 'Farinha', 'quantity' => '10', 'unit' => 'kg', 'unit_price' => '8', 'total_price' => '80']],
            'received' => true,
            'source' => 'forged',
            'document_status' => 'cancelled',
        ], 'purchase-create');
        $document = PurchaseDocument::query()->findOrFail($purchase->result['id']);
        $item = $document->items()->sole();
        $this->assertSame('0.000000', app(IngredientStockService::class)->balance($ingredient->id, $this->factory->id));
        $this->assertSame('agent', $document->source);
        $this->assertNotSame('cancelled', $document->document_status);
        $this->assertDatabaseCount('purchase_receipts', 0);

        $action = $this->prepare('purchases.receipts.receive', ['document_id' => $document->id, 'received_date' => '2026-08-24', 'items' => [['item_id' => $item->id, 'quantity' => '4']]], 'receipt-partial');
        $first = app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
        app(PendingAgentActionService::class)->confirm($first, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('partially_received', $document->fresh()->receipt_status);
        $this->assertSame('4000.000000', app(IngredientStockService::class)->balance($ingredient->id, $this->factory->id));
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('ingredient_stock_movements', 1);
    }

    public function test_purchase_receipt_revalidates_remaining_quantity_at_confirmation(): void
    {
        [$supplier, $ingredient] = $this->pricedIngredient();
        $document = $this->purchase($supplier, $ingredient, '5', '40', 'purchase-over');
        $item = $document->items()->sole();
        $action = $this->prepare('purchases.receipts.receive', ['document_id' => $document->id, 'received_date' => '2026-08-24', 'items' => [['item_id' => $item->id, 'quantity' => '6']]], 'receipt-over');

        try {
            app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
            $this->fail('Recebimento superior ao saldo deveria falhar.');
        } catch (DomainException) {
            $this->assertDatabaseCount('purchase_receipts', 0);
            $this->assertDatabaseCount('ingredient_stock_movements', 0);
        }
    }

    public function test_payable_and_partial_payment_are_confirmed_once_and_ignore_tampered_fields(): void
    {
        $payableAction = $this->prepare('finance.payables.create', [
            'description' => 'Conta operacional', 'location_id' => $this->store->id, 'expected_amount' => '100',
            'competency_date' => '2026-08-24', 'due_date' => '2026-08-30', 'recurring' => false,
            'status' => 'paid', 'source' => 'forged', 'created_by' => 999999,
        ], 'payable-create');
        app(PendingAgentActionService::class)->confirm($payableAction, $this->admin, app(AgentToolExecutor::class));
        $payable = Payable::query()->sole();
        $this->assertSame('pending', $payable->status);
        $this->assertSame('agent', $payable->source);
        $this->assertSame($this->admin->id, $payable->created_by);

        $account = FinancialAccount::query()->create(['name' => 'Conta Ibirá', 'type' => 'bank', 'location_id' => $this->store->id, 'active' => true]);
        $paymentAction = $this->prepare('finance.payments.record', ['payable_id' => $payable->id, 'amount' => '40', 'paid_at' => '2026-08-24', 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'partner_advance' => false, 'status' => 'cancelled'], 'payment-partial');
        $executed = app(PendingAgentActionService::class)->confirm($paymentAction, $this->admin, app(AgentToolExecutor::class));
        app(PendingAgentActionService::class)->confirm($executed, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('partially_paid', $payable->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['amount' => '40.00', 'status' => 'completed', 'source' => 'agent']);
    }

    public function test_payment_revalidates_payable_balance_changed_after_preview(): void
    {
        $payable = app(CreatePayableService::class)->create($this->payableData('100', 'balance-change'), $this->admin);
        $account = FinancialAccount::query()->create(['name' => 'Conta Central', 'type' => 'bank', 'location_id' => $this->factory->id, 'active' => true]);
        $action = $this->prepare('finance.payments.record', ['payable_id' => $payable->id, 'amount' => '70', 'paid_at' => '2026-08-24', 'financial_account_id' => $account->id, 'payment_method' => 'pix'], 'payment-late');
        app(RegisterPaymentService::class)->register($payable, ['amount' => '50', 'paid_at' => '2026-08-24', 'financial_account_id' => $account->id, 'payment_method' => 'pix', 'partner_advance' => false, 'idempotency_key' => 'payment-first'], $this->admin);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede o saldo');
        app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
    }

    public function test_permission_and_active_user_are_revalidated_at_confirmation(): void
    {
        $user = User::factory()->unprivileged()->create(['active' => true]);
        $permission = Permission::query()->where('name', 'finance.payables.create')->firstOrFail();
        $user->permissions()->attach($permission, ['allowed' => true]);
        $user->locations()->attach($this->store);
        $action = app(PendingAgentActionService::class)->prepare($user, 'finance.payables.create', [...$this->payableData('20', 'permission'), 'location_id' => $this->store->id], [], 'permission-pending');
        $user->permissions()->updateExistingPivot($permission->id, ['allowed' => false]);
        try {
            app(PendingAgentActionService::class)->confirm($action, $user, app(AgentToolExecutor::class));
            $this->fail('Permissão revogada deveria bloquear.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('payables', 0);
        }

        $user->permissions()->updateExistingPivot($permission->id, ['allowed' => true]);
        $inactive = app(PendingAgentActionService::class)->prepare($user, 'finance.payables.create', [...$this->payableData('20', 'inactive'), 'location_id' => $this->store->id], [], 'inactive-pending');
        $user->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        app(PendingAgentActionService::class)->confirm($inactive, $user, app(AgentToolExecutor::class));
    }

    public function test_production_without_recipe_or_without_ingredient_balance_never_writes_partial_data(): void
    {
        $product = Product::query()->create(['name' => 'Sem Ficha', 'stock_unit' => 'un', 'active' => true]);
        foreach ([
            ['key' => 'production-no-recipe', 'product' => $product],
            ['key' => 'production-no-stock', 'product' => $this->recipeProduct('Com Ficha')[0]],
        ] as $case) {
            $action = $this->prepare('production.orders.complete_batch', ['location_id' => $this->factory->id, 'production_date' => '2026-08-24', 'items' => [['product_id' => $case['product']->id, 'produced_quantity' => '2']]], $case['key']);
            try {
                app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
                $this->fail('Produção inválida deveria falhar.');
            } catch (DomainException) {
                $this->assertDatabaseCount('production_orders', 0);
                $this->assertDatabaseCount('stock_movements', 0);
            }
        }
    }

    public function test_valid_production_consumes_ingredients_and_adds_product_stock_once(): void
    {
        [$product, $ingredient] = $this->recipeProduct('Produção Válida');
        app(IngredientStockService::class)->record(['ingredient_id' => $ingredient->id, 'location_id' => $this->factory->id, 'type' => 'positive_adjustment', 'quantity_delta' => '500', 'operation_date' => '2026-08-24', 'idempotency_key' => 'ingredient-opening']);
        $action = $this->prepare('production.orders.complete_batch', ['location_id' => $this->factory->id, 'production_date' => '2026-08-24', 'items' => [['product_id' => $product->id, 'produced_quantity' => '2']]], 'production-valid');
        $executed = app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
        app(PendingAgentActionService::class)->confirm($executed, $this->admin, app(AgentToolExecutor::class));

        $this->assertSame('300.000000', app(IngredientStockService::class)->balance($ingredient->id, $this->factory->id));
        $this->assertSame('2.000000', app(StockBalanceService::class)->balance($product, $this->factory));
        $this->assertDatabaseCount('production_orders', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_loss_preview_shows_balance_and_confirmation_revalidates_changed_stock(): void
    {
        $product = Product::query()->create(['name' => 'Produto Perda', 'stock_unit' => 'un', 'active' => true]);
        $reason = LossReason::query()->create(['name' => 'Quebra', 'active' => true]);
        $this->productStock($product, $this->store, '10', 'loss-opening');
        $preview = $this->agent('loss-preview', ['tool' => 'losses.record', 'arguments' => ['product_name' => 'Produto Perda', 'location_id' => $this->store->id, 'loss_reason_name' => 'Quebra', 'quantity' => '4', 'operation_date' => '2026-08-24']]);
        $this->assertStringContainsString('Saldo atual: 10', $preview->message);
        $this->assertStringContainsString('Saldo após confirmação: 6', $preview->message);
        $this->answer('loss-confirm', 'SIM');
        $this->assertSame('6.000000', app(StockBalanceService::class)->balance($product, $this->store));

        $action = $this->prepare('losses.record', ['product_id' => $product->id, 'location_id' => $this->store->id, 'loss_reason_id' => $reason->id, 'quantity' => '5', 'operation_date' => '2026-08-24'], 'loss-late');
        $this->productStock($product, $this->store, '-3', 'loss-external-out');
        try {
            app(PendingAgentActionService::class)->confirm($action, $this->admin, app(AgentToolExecutor::class));
            $this->fail('Saldo alterado deveria bloquear a perda.');
        } catch (DomainException) {
            $this->assertDatabaseCount('product_losses', 1);
            $this->assertSame('3.000000', app(StockBalanceService::class)->balance($product, $this->store));
        }
    }

    public function test_transfer_has_distinct_create_dispatch_receive_effects_and_idempotency(): void
    {
        $product = Product::query()->create(['name' => 'Produto Transferência', 'stock_unit' => 'un', 'active' => true]);
        $this->productStock($product, $this->factory, '10', 'transfer-opening');
        $create = $this->prepare('transfers.create', ['source_location_id' => $this->factory->id, 'destination_location_id' => $this->store->id, 'product_id' => $product->id, 'quantity' => '6', 'operation_date' => '2026-08-24'], 'transfer-create');
        app(PendingAgentActionService::class)->confirm($create, $this->admin, app(AgentToolExecutor::class));
        $transfer = StockTransfer::query()->sole();
        $this->assertSame(StockTransferStatus::Pending, $transfer->status);
        $this->assertSame('10.000000', app(StockBalanceService::class)->balance($product, $this->factory));
        $this->assertSame('0.000000', app(StockBalanceService::class)->balance($product, $this->store));

        $dispatch = $this->prepare('transfers.dispatch', ['transfer_id' => $transfer->id, 'dispatch_date' => '2026-08-24'], 'transfer-dispatch');
        app(PendingAgentActionService::class)->confirm($dispatch, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('4.000000', app(StockBalanceService::class)->balance($product, $this->factory));
        $this->assertSame('0.000000', app(StockBalanceService::class)->balance($product, $this->store));

        $receive = $this->prepare('transfers.receive', ['transfer_id' => $transfer->id, 'received_date' => '2026-08-24', 'quantity_received' => '6'], 'transfer-receive');
        $executed = app(PendingAgentActionService::class)->confirm($receive, $this->admin, app(AgentToolExecutor::class));
        app(PendingAgentActionService::class)->confirm($executed, $this->admin, app(AgentToolExecutor::class));
        $this->assertSame('6.000000', app(StockBalanceService::class)->balance($product, $this->store));
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_transfer_rejects_insufficient_stock_same_location_and_unauthorized_destination(): void
    {
        $product = Product::query()->create(['name' => 'Produto Restrito', 'stock_unit' => 'un', 'active' => true]);
        $same = ['source_location_id' => $this->factory->id, 'destination_location_id' => $this->factory->id, 'product_id' => $product->id, 'quantity' => '1', 'operation_date' => '2026-08-24', 'idempotency_key' => 'same-location'];
        try {
            app(AgentToolExecutor::class)->execute('transfers.create', $same, $this->admin, true);
            $this->fail('Origem e destino iguais deveriam falhar.');
        } catch (DomainException) {
            $this->assertDatabaseCount('stock_transfers', 0);
        }

        $transfer = app(AgentToolExecutor::class)->execute('transfers.create', [...$same, 'destination_location_id' => $this->store->id, 'quantity' => '2', 'idempotency_key' => 'insufficient-transfer'], $this->admin, true);
        $this->expectException(DomainException::class);
        app(AgentToolExecutor::class)->execute('transfers.dispatch', ['transfer_id' => $transfer->id, 'dispatch_date' => '2026-08-24'], $this->admin, true);
    }

    public function test_transfer_requires_permission_for_both_units(): void
    {
        $product = Product::query()->create(['name' => 'Produto Multiunidade', 'stock_unit' => 'un', 'active' => true]);
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', 'transfers.create')->firstOrFail(), ['allowed' => true]);
        $user->locations()->attach($this->factory);

        $this->expectException(AuthorizationException::class);
        app(AgentToolExecutor::class)->execute('transfers.create', ['source_location_id' => $this->factory->id, 'destination_location_id' => $this->store->id, 'product_id' => $product->id, 'quantity' => '1', 'operation_date' => '2026-08-24', 'idempotency_key' => 'cross-location'], $user, true);
    }

    public function test_ambiguous_product_resolution_and_multiple_pending_actions_never_choose_arbitrarily(): void
    {
        Product::query()->create(['name' => 'Frango Tradicional', 'stock_unit' => 'un', 'active' => true]);
        Product::query()->create(['name' => 'Frango Especial', 'stock_unit' => 'un', 'active' => true]);
        $ambiguous = $this->agent('ambiguous-product', ['tool' => 'losses.record', 'arguments' => ['product_name' => 'Frango', 'location_id' => $this->store->id, 'loss_reason_id' => 999, 'quantity' => '1', 'operation_date' => '2026-08-24']]);
        $this->assertStringContainsString('possibilidade', $ambiguous->message);
        $this->assertDatabaseCount('product_losses', 0);
        $this->answer('ambiguous-cancel', 'CANCELAR');

        $conversation = app(AgentConversationService::class)->conversation($this->admin, 'local-test', 'operational-master');
        app(PendingAgentActionService::class)->prepare($this->admin, 'finance.payables.create', $this->payableData('10', 'first'), [], 'multiple-first', $conversation->id);
        app(PendingAgentActionService::class)->prepare($this->admin, 'finance.payables.create', $this->payableData('20', 'second'), [], 'multiple-second', $conversation->id);
        $response = $this->answer('multiple-answer', 'SIM');
        $this->assertSame('multiple_pending_actions', $response->errorCode);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_transcribed_audio_uses_same_operational_preview_and_confirmation_flow(): void
    {
        $preview = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'operational-master', 'audio-operational', 'transcrição local', 'transcribed_audio', metadata: ['fake_intent' => ['tool' => 'catalog.suppliers.create', 'arguments' => ['name' => 'Fornecedor por Áudio']]]));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseMissing('suppliers', ['name' => 'Fornecedor por Áudio']);
        $this->answer('audio-confirm', 'SIM');
        $this->assertDatabaseHas('suppliers', ['name' => 'Fornecedor por Áudio']);
    }

    private function agent(string $messageId, array $intent)
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'operational-master', $messageId, 'solicitação operacional', metadata: ['fake_intent' => $intent]));
    }

    private function answer(string $messageId, string $text)
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'operational-master', $messageId, $text));
    }

    private function prepare(string $tool, array $payload, string $key)
    {
        $payload['idempotency_key'] = $key;

        return app(PendingAgentActionService::class)->prepare($this->admin, $tool, $payload, [], $key.':pending');
    }

    private function confirmed(string $tool, array $payload, string $key)
    {
        return app(PendingAgentActionService::class)->confirm($this->prepare($tool, $payload, $key), $this->admin, app(AgentToolExecutor::class));
    }

    /** @return array{Supplier, Ingredient} */
    private function pricedIngredient(): array
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor Compra', 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Farinha Compra', 'base_unit' => 'g', 'active' => true]);

        return [$supplier, $ingredient];
    }

    private function purchase(Supplier $supplier, Ingredient $ingredient, string $quantity, string $total, string $key): PurchaseDocument
    {
        $this->confirmed('purchases.documents.create', ['location_id' => $this->factory->id, 'supplier_id' => $supplier->id, 'document_type' => 'invoice', 'issue_date' => '2026-08-24', 'total_amount' => $total, 'items' => [['ingredient_id' => $ingredient->id, 'description' => $ingredient->name, 'quantity' => $quantity, 'unit' => 'kg', 'unit_price' => '8', 'total_price' => $total]]], $key);

        return PurchaseDocument::query()->where('idempotency_key', $key)->firstOrFail();
    }

    /** @return array{Product, Ingredient} */
    private function recipeProduct(string $name): array
    {
        $supplier = Supplier::query()->create(['name' => 'Fornecedor '.$name, 'active' => true]);
        $ingredient = Ingredient::query()->create(['name' => 'Insumo '.$name, 'base_unit' => 'g', 'active' => true]);
        app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '10', 'effective_date' => '2026-08-24']);
        $product = Product::query()->create(['name' => $name, 'stock_unit' => 'un', 'active' => true]);
        app(ProductRecipeService::class)->save($product, ['yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0', 'ingredients' => [['ingredient_id' => $ingredient->id, 'quantity' => '100', 'unit' => 'g']]]);

        return [$product, $ingredient];
    }

    private function payableData(string $amount, string $key): array
    {
        return ['description' => 'Conta '.$key, 'location_id' => $this->factory->id, 'expected_amount' => $amount, 'competency_date' => '2026-08-24', 'due_date' => '2026-08-30', 'recurring' => false, 'idempotency_key' => $key];
    }

    private function productStock(Product $product, Location $location, string $quantity, string $key): void
    {
        app(StockMovementService::class)->record(new RecordStockMovementData($product->id, $location->id, StockMovementType::Adjustment, $quantity, '2026-08-24', $key, $this->admin->id));
    }
}
