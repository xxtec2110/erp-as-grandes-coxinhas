<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\AgentToolExecutor;
use App\Agent\AgentToolRegistry;
use App\Agent\ErpAgentService;
use App\Models\Ingredient;
use App\Models\Permission;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\IngredientSemanticResolver;
use App\Services\ProductCatalogService;
use App\Services\ProductMatchService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterCatalogAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_master_collects_product_fields_previews_corrects_confirms_and_is_idempotent(): void
    {
        $this->master('master');

        $this->assertStringContainsString('nome do produto', $this->agent('master', 'QUERO CRIAR UM NOVO SABOR DE COXINHA', 'p-1')->message);
        $this->assertStringContainsString('preço de venda', $this->agent('master', 'Coxinha Especial', 'p-2')->message);
        $preview = $this->agent('master', 'R$ 22,00', 'p-3');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('sem ficha técnica e sem custo calculado', $preview->message);
        $this->assertDatabaseCount('products', 0);

        $corrected = $this->agent('master', 'Troca o preço para R$ 23,00', 'p-4');
        $this->assertStringContainsString('R$ 23,00', $corrected->message);
        $this->agent('master', '1', 'p-5');
        $this->agent('master', 'SIM', 'p-6');

        $product = Product::query()->where('name', 'Coxinha Especial')->firstOrFail();
        $this->assertSame('23.0000', $product->currentPrice->price);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_recipes', 0);
        $this->assertDatabaseCount('catalog_admin_audits', 1);
    }

    public function test_non_master_is_denied_and_cancellation_writes_nothing(): void
    {
        $this->identity(User::factory()->unprivileged()->create(), 'common', ['agent.text.use']);
        $denied = $this->agent('common', 'QUERO CRIAR UM NOVO SABOR DE COXINHA', 'deny-1');
        $this->assertFalse($denied->success);
        $this->assertSame('forbidden', $denied->errorCode);

        $this->master('master-cancel');
        $this->agent('master-cancel', 'CRIE Sabor Cancelado POR R$ 20', 'cancel-1');
        $this->agent('master-cancel', '2', 'cancel-2');
        $this->assertDatabaseMissing('products', ['name' => 'Sabor Cancelado']);
    }

    public function test_explicit_catalog_tools_reuse_services_for_updates_prices_suppliers_and_ingredients(): void
    {
        $user = $this->master('tool-master');
        $executor = app(AgentToolExecutor::class);
        $product = Product::query()->create(['name' => 'Costela com queijo', 'stock_unit' => 'un', 'active' => true]);

        $executor->execute('catalog.products.update_price', ['product_id' => $product->id, 'selling_price' => '22', 'idempotency_key' => 'price-1'], $user, true);
        $executor->execute('catalog.products.update_price', ['product_id' => $product->id, 'selling_price' => '24', 'idempotency_key' => 'price-2'], $user, true);
        $executor->execute('catalog.products.update_price', ['product_id' => $product->id, 'selling_price' => '24', 'idempotency_key' => 'price-2'], $user, true);
        $this->assertSame('24.0000', $product->fresh()->currentPrice->price);
        $this->assertSame(2, $product->prices()->count());

        $supplier = $executor->execute('catalog.suppliers.create', ['name' => 'Fornecedor Oficial', 'document_number' => '11222333000181', 'idempotency_key' => 'supplier-1'], $user, true);
        $ingredient = $executor->execute('catalog.ingredients.create', ['name' => 'Muçarela', 'base_unit' => 'g', 'idempotency_key' => 'ingredient-1'], $user, true);
        $executor->execute('catalog.ingredient_prices.add', ['ingredient_id' => $ingredient->id, 'supplier_id' => $supplier->id, 'purchase_quantity' => '5', 'purchase_unit' => 'kg', 'price_paid' => '220', 'effective_date' => '2026-08-15', 'idempotency_key' => 'ingredient-price-1'], $user, true);

        $this->assertSame('0.04400000', $ingredient->fresh()->currentPrice->base_unit_cost);
        $this->assertDatabaseCount('catalog_admin_audits', 5);
    }

    public function test_supplier_duplicates_and_catupiry_artificial_ingredient_are_rejected_without_partial_writes(): void
    {
        $user = $this->master('validation-master');
        $executor = app(AgentToolExecutor::class);
        $executor->execute('catalog.suppliers.create', ['name' => 'Fornecedor Um', 'document_number' => '11222333000181', 'idempotency_key' => 's-1'], $user, true);

        try {
            $executor->execute('catalog.suppliers.create', ['name' => 'Fornecedor Dois', 'document_number' => '11222333000181', 'idempotency_key' => 's-2'], $user, true);
            $this->fail('CNPJ duplicado deveria ser recusado.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('suppliers', 1);
        }
        try {
            $executor->execute('catalog.ingredients.create', ['name' => 'Catupiry', 'base_unit' => 'g', 'brand' => 'Inventada', 'idempotency_key' => 'i-1'], $user, true);
            $this->fail('Catupiry não deve virar insumo.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('ingredients', 0);
        }
        $resolution = app(IngredientSemanticResolver::class)->resolve('catupiry');
        $this->assertSame('requeijao', $resolution['concept']);
        $this->assertNull($resolution['brand']);
    }

    public function test_preparation_recipe_alias_and_transactional_rollback_use_explicit_tools(): void
    {
        $user = $this->master('recipe-master');
        $executor = app(AgentToolExecutor::class);
        $product = Product::query()->create(['name' => 'Produto Receita', 'stock_unit' => 'un', 'active' => true]);
        $preparation = $executor->execute('catalog.preparations.create', ['name' => 'Recheio Base', 'expected_yield' => '10', 'yield_unit' => 'kg', 'total_preparation_time_minutes' => 60, 'idempotency_key' => 'prep-1'], $user, true);
        $recipe = $executor->execute('catalog.product_recipes.create', ['product_id' => $product->id, 'yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0', 'preparations' => [['preparation_id' => $preparation->id, 'quantity' => '50', 'unit' => 'g']], 'idempotency_key' => 'recipe-1'], $user, true);
        $executor->execute('catalog.product_aliases.create', ['product_id' => $product->id, 'alias' => 'Produto R', 'idempotency_key' => 'alias-1'], $user, true);
        $this->assertSame($product->id, $recipe->product_id);
        $this->assertDatabaseHas('product_aliases', ['product_id' => $product->id, 'normalized_name' => 'produto r']);

        try {
            $executor->execute('catalog.product_recipes.update', ['product_id' => $product->id, 'yield_quantity' => '1', 'technical_loss_percentage' => '0', 'packaging_cost' => '0', 'ingredients' => [['ingredient_id' => 999999, 'quantity' => '1', 'unit' => 'g']], 'idempotency_key' => 'recipe-invalid'], $user, true);
            $this->fail('Componente inválido deveria causar rollback.');
        } catch (\Throwable) {
            $this->assertSame(1, $product->recipe->preparations()->count());
        }
    }

    public function test_transcribed_fake_audio_uses_same_preview_permission_and_confirmation_flow(): void
    {
        $this->master('audio-master', true);
        $intent = ['tool' => 'catalog.products.create', 'arguments' => ['name' => 'Produto por Áudio', 'selling_price' => '18']];
        $preview = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'audio-master', 'audio-1', 'transcrição fake', 'transcribed_audio', metadata: ['fake_intent' => $intent]));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertDatabaseMissing('products', ['name' => 'Produto por Áudio']);
        $this->agent('audio-master', 'OK', 'audio-2');
        $this->assertDatabaseHas('products', ['name' => 'Produto por Áudio']);
    }

    public function test_price_change_preview_shows_previous_and_new_value_before_writing(): void
    {
        $user = $this->master('price-master');
        $product = app(ProductCatalogService::class)->create(['name' => 'Costela com queijo', 'stock_unit' => 'un', 'active' => true, 'selling_price' => '22'], [], $user);

        $preview = $this->agent('price-master', 'ALTERE Costela com queijo PARA R$ 24', 'change-price-1');
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('Preço atual: R$ 22,00', $preview->message);
        $this->assertStringContainsString('Novo preço: R$ 24,00', $preview->message);
        $this->assertSame('22.0000', $product->fresh()->currentPrice->price);
        $this->agent('price-master', 'CONFIRMAR', 'change-price-2');
        $this->assertSame('24.0000', $product->fresh()->currentPrice->price);
        $this->assertSame(2, $product->prices()->count());
    }

    public function test_preparation_draft_accumulates_ingredients_and_protected_catupiry_requires_real_binding(): void
    {
        $this->master('prep-draft');
        $costela = Ingredient::query()->create(['name' => 'Costela', 'base_unit' => 'g', 'active' => true]);
        $cebola = Ingredient::query()->create(['name' => 'Cebola', 'base_unit' => 'g', 'active' => true]);
        $intent = ['tool' => 'catalog.preparations.create', 'arguments' => ['name' => 'Recheio de costela', 'expected_yield' => '10', 'yield_unit' => 'kg', 'total_preparation_time_minutes' => 90]];
        $preview = app(ErpAgentService::class)->handle(new AgentMessage('local-test', 'prep-draft', 'prep-draft-1', 'criar recheio', metadata: ['fake_intent' => $intent]));
        $this->assertSame('confirmation', $preview->responseType);
        $this->assertStringContainsString('10 kg de Costela', $this->agent('prep-draft', '10 kg de Costela', 'prep-draft-2')->message);
        $this->assertStringContainsString('1 kg de Cebola', $this->agent('prep-draft', '1 kg de Cebola', 'prep-draft-3')->message);
        $blocked = $this->agent('prep-draft', '60 g de catupiry', 'prep-draft-4');
        $this->assertSame('ingredient_binding_required', $blocked->errorCode);
        $this->agent('prep-draft', 'PODE CRIAR', 'prep-draft-5');

        $preparation = Preparation::query()->where('name', 'Recheio de costela')->firstOrFail();
        $this->assertEqualsCanonicalizing([$costela->id, $cebola->id], $preparation->preparationIngredients()->pluck('ingredient_id')->all());
        $this->assertDatabaseMissing('ingredients', ['name' => 'Catupiry']);
    }

    public function test_official_sync_preserves_order_prices_ids_and_deterministic_matching_without_recipes_or_stock(): void
    {
        $items = [
            ['sort_order' => 1, 'name' => 'Frango com catupiry', 'price' => '16.00'],
            ['sort_order' => 2, 'name' => 'Costela com queijo', 'price' => '22.00'],
            ['sort_order' => 3, 'name' => 'Alcatra com provolone', 'price' => '22.00'],
        ];
        $result = app(ProductCatalogService::class)->syncOfficial($items);
        $firstId = Product::query()->where('name', 'Frango com catupiry')->value('id');
        app(ProductCatalogService::class)->syncOfficial($items);

        $this->assertSame(3, $result['created']);
        $this->assertSame($firstId, Product::query()->where('name', 'Frango com catupiry')->value('id'));
        $this->assertSame([1, 2, 3], Product::query()->orderBy('sort_order')->pluck('sort_order')->all());
        $this->assertDatabaseCount('product_recipes', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('Coxinhas', ProductCategory::query()->sole()->name);
        foreach ($items as $item) {
            $resolved = app(ProductMatchService::class)->resolveExactItems([['product_name' => $item['name']]])[0];
            $this->assertSame('resolved', $resolved['_product_match']['status']);
        }
    }

    public function test_registry_contains_only_explicit_catalog_tools_with_existing_permissions(): void
    {
        $tools = collect(app(AgentToolRegistry::class)->all())->filter(fn ($tool) => str_starts_with($tool->name, 'catalog.'));
        $this->assertCount(13, $tools);
        $this->assertFalse($tools->has('admin.sql'));
        foreach ($tools as $tool) {
            $this->assertTrue(Permission::query()->where('name', $tool->permission)->exists(), $tool->permission);
            $this->assertTrue($tool->writesData);
            $this->assertTrue($tool->confirmationRequired);
        }
    }

    private function master(string $external, bool $audio = false): User
    {
        $user = User::factory()->unprivileged()->create(['name' => 'Administrador Master']);
        $user->roles()->attach(Role::query()->where('name', 'administrator')->firstOrFail());
        $this->identity($user, $external, [], $audio);

        return $user;
    }

    private function identity(User $user, string $external, array $permissions = [], bool $audio = false): void
    {
        foreach ($permissions as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        UserExternalIdentity::query()->create(['user_id' => $user->id, 'channel' => 'local-test', 'external_user_id' => $external, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'voice_allowed' => $audio]);
    }

    private function agent(string $external, string $text, string $messageId)
    {
        return app(ErpAgentService::class)->handle(new AgentMessage('local-test', $external, $messageId, $text));
    }
}
