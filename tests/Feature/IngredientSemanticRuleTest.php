<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\FakeAudioTranscriptionProvider;
use App\Models\AgentAttachment;
use App\Models\Ingredient;
use App\Models\IngredientConcept;
use App\Models\IngredientConceptBinding;
use App\Models\Location;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AiInterpretationService;
use App\Services\IngredientSemanticResolver;
use App\Services\IngredientStockService;
use App\Services\ProductionOrderService;
use App\Services\ProductRecipeCostService;
use App\Services\ProductRecipeService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientSemanticRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catupiry_variants_resolve_to_protected_requeijao_concept_without_inventing_brand_or_ingredient(): void
    {
        $resolver = app(IngredientSemanticResolver::class);

        foreach (['catupiry', 'Catupiry', 'CATUPIRY'] as $term) {
            $resolution = $resolver->resolve($term);
            $this->assertSame('requeijao', $resolution['concept']);
            $this->assertSame('Requeijão', $resolution['concept_label']);
            $this->assertSame('target_missing', $resolution['status']);
            $this->assertTrue($resolution['protected']);
            $this->assertNull($resolution['brand']);
            $this->assertNull($resolution['ingredient_id']);
        }

        $requeijao = $resolver->resolve('requeijão');
        $this->assertSame('requeijao', $requeijao['concept']);
        $this->assertSame('target_missing', $requeijao['status']);
        $this->assertDatabaseCount('ingredients', 0);
        $this->assertDatabaseMissing('ingredients', ['name' => 'Catupiry']);
    }

    public function test_isolated_catupiry_cannot_be_created_as_an_artificial_ingredient_stock(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('ingredients.store'), [
            'name' => 'Catupiry',
            'base_unit' => 'g',
            'active' => '1',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('ingredients', 0);

        $this->actingAs($user)->post(route('ingredients.store'), [
            'name' => 'Requeijão',
            'base_unit' => 'g',
            'active' => '1',
        ])->assertSessionDoesntHaveErrors('name');
        $this->assertDatabaseHas('ingredients', ['name' => 'Requeijão']);
    }

    public function test_single_requeijao_resolves_deterministically_and_commercial_product_name_is_unchanged(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca operacional', 'base_unit' => 'g', 'active' => true]);
        $product = Product::query()->create(['name' => 'Frango com catupiry', 'stock_unit' => 'un', 'active' => true]);

        $resolution = app(IngredientSemanticResolver::class)->resolve('catupiry');

        $this->assertSame('resolved', $resolution['status']);
        $this->assertSame($ingredient->id, $resolution['ingredient_id']);
        $this->assertSame('single_exact_concept', $resolution['resolution_source']);
        $this->assertSame('Frango com catupiry', $product->fresh()->name);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_recipes', 0);
    }

    public function test_multiple_requeijoes_are_ambiguous_but_explicit_brand_or_binding_resolves_them(): void
    {
        $first = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca A', 'base_unit' => 'g', 'active' => true]);
        $second = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca X', 'base_unit' => 'g', 'active' => true]);
        $resolver = app(IngredientSemanticResolver::class);

        $ambiguous = $resolver->resolve('catupiry');
        $this->assertSame('ambiguous', $ambiguous['status']);
        $this->assertNull($ambiguous['ingredient_id']);

        $explicitBrand = $resolver->resolve('requeijão', 'Marca X', true);
        $this->assertSame($second->id, $explicitBrand['ingredient_id']);
        $this->assertSame('Marca X', $explicitBrand['brand']);
        $this->assertSame('explicit_brand', $explicitBrand['resolution_source']);

        $resolver->bind(IngredientConcept::query()->where('code', 'requeijao')->firstOrFail(), $first, User::factory()->create(), 'Definição administrativa');
        $bound = $resolver->resolve('CATUPIRY');
        $this->assertSame($first->id, $bound['ingredient_id']);
        $this->assertSame('explicit_binding', $bound['resolution_source']);
    }

    public function test_protected_rule_cannot_be_changed_by_common_user_or_deleted_directly(): void
    {
        $concept = IngredientConcept::query()->where('code', 'requeijao')->firstOrFail();
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'base_unit' => 'g', 'active' => true]);

        try {
            app(IngredientSemanticResolver::class)->bind($concept, $ingredient, User::factory()->unprivileged()->create());
            $this->fail('Um usuário comum não deveria alterar o vínculo operacional.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('ingredient_concept_bindings', 0);
        }

        $this->expectException(DomainException::class);
        $concept->delete();
    }

    public function test_recipe_cost_stock_and_production_use_real_ingredient_and_preserve_historical_snapshot_after_binding_change(): void
    {
        $user = User::factory()->create();
        $location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Fornecedor', 'active' => true]);
        $first = $this->pricedRequeijao('Marca A', '0.01000000', $supplier);
        $second = $this->pricedRequeijao('Marca B', '0.02000000', $supplier);
        $concept = IngredientConcept::query()->where('code', 'requeijao')->firstOrFail();
        $resolver = app(IngredientSemanticResolver::class);
        $resolver->bind($concept, $first, $user, 'Primeiro vínculo oficial');
        $product = Product::query()->create(['name' => 'Frango com catupiry', 'stock_unit' => 'un', 'active' => true]);
        $recipe = app(ProductRecipeService::class)->save($product, [
            'yield_quantity' => '1',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '0',
            'ingredients' => [['ingredient_id' => $first->id, 'quantity' => '60', 'unit' => 'g']],
        ]);

        $cost = app(ProductRecipeCostService::class)->calculate($recipe);
        $this->assertSame('0.60000000', $cost['ingredients_cost']);
        app(IngredientStockService::class)->record(['ingredient_id' => $first->id, 'location_id' => $location->id, 'type' => 'positive_adjustment', 'quantity_delta' => '100', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'semantic-opening', 'created_by' => $user->id]);
        $order = app(ProductionOrderService::class)->plan(['location_id' => $location->id, 'production_date' => now()->toDateString(), 'idempotency_key' => 'semantic-production', 'items' => [['product_id' => $product->id, 'planned_quantity' => '1']]], $user);
        $this->assertSame($first->id, $order->items->sole()->recipe_snapshot['consumption_per_product'][0]['ingredient_id']);

        $resolver->bind($concept, $second, $user, 'Troca futura de marca');
        app(ProductionOrderService::class)->complete($order, [$order->items->sole()->id => '1'], $user);

        $this->assertSame('40.000000', app(IngredientStockService::class)->balance($first->id, $location->id));
        $this->assertSame('0.000000', app(IngredientStockService::class)->balance($second->id, $location->id));
        $this->assertSame($second->id, $resolver->resolve('catupiry')['ingredient_id']);
        $this->assertSame('Frango com catupiry', $product->fresh()->name);
        $this->assertDatabaseCount('ingredient_concept_bindings', 2);
        $this->assertSame(1, IngredientConceptBinding::query()->whereNotNull('effective_until')->count());
        $this->assertDatabaseMissing('ingredients', ['name' => 'Catupiry']);
    }

    public function test_fake_agent_text_resolves_catupiry_without_assuming_a_brand(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca real', 'base_unit' => 'g', 'active' => true]);
        $message = new AgentMessage('local-test', 'semantic-text', 'semantic-text-1', 'Vai 50 g de catupiry.', metadata: ['fake_intent' => [
            'tool' => 'purchases.documents.create',
            'fields' => ['items' => [['ingredient_name' => 'Catupiry', 'ingredient_brand' => 'Catupiry', 'ingredient_brand_explicit' => false, 'quantity' => '50', 'unit' => 'g']]],
        ]]);

        $interpretation = app(AiInterpretationService::class)->interpret($message, ['purchases.documents.create'], User::factory()->create());
        $item = $interpretation->fields['items'][0];

        $this->assertSame($ingredient->id, $item['ingredient_id']);
        $this->assertSame('Requeijão', $item['ingredient_concept']);
        $this->assertSame('requeijao', $item['_ingredient_semantic']['concept']);
        $this->assertFalse($item['_ingredient_semantic']['brand_explicit']);
        $this->assertArrayNotHasKey('ingredient_brand', $item);
    }

    public function test_fake_audio_transcription_uses_the_same_semantic_resolver(): void
    {
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'base_unit' => 'g', 'active' => true]);
        $attachment = new AgentAttachment(['metadata' => ['fake_transcription' => 'coloca sessenta gramas de catupiry']]);
        $transcription = app(FakeAudioTranscriptionProvider::class)->transcribe($attachment);
        $message = new AgentMessage('local-test', 'semantic-audio', 'semantic-audio-1', $transcription->text, 'transcribed_audio', metadata: ['fake_intent' => [
            'tool' => 'purchases.documents.create',
            'fields' => ['items' => [['ingredient_name' => 'catupiry', 'quantity' => '60', 'unit' => 'g']]],
        ]]);

        $interpretation = app(AiInterpretationService::class)->interpret($message, ['purchases.documents.create'], User::factory()->create());
        $item = $interpretation->fields['items'][0];

        $this->assertSame('coloca sessenta gramas de catupiry', $transcription->text);
        $this->assertSame($ingredient->id, $item['ingredient_id']);
        $this->assertSame('requeijao', $item['_ingredient_semantic']['concept']);
        $this->assertSame('60', $item['quantity']);
    }

    public function test_fake_price_language_previews_unique_real_ingredient_and_requires_choice_when_ambiguous_without_writing_price(): void
    {
        $first = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca A', 'base_unit' => 'g', 'active' => true]);
        $service = app(AiInterpretationService::class);
        $user = User::factory()->create();
        $unique = $service->interpret(new AgentMessage('local-test', 'semantic-price', 'semantic-price-1', 'Atualiza o catupiry para R$ 40.', metadata: ['fake_intent' => [
            'tool' => 'purchases.documents.create',
            'fields' => ['items' => [['ingredient_name' => 'catupiry', 'price_paid' => '40.00']]],
        ]]), ['purchases.documents.create'], $user);

        $this->assertSame($first->id, $unique->fields['items'][0]['ingredient_id']);
        $this->assertSame('40.00', $unique->fields['items'][0]['price_paid']);
        $this->assertDatabaseCount('ingredient_prices', 0);

        Ingredient::query()->create(['name' => 'Requeijão', 'brand' => 'Marca B', 'base_unit' => 'g', 'active' => true]);
        $ambiguous = $service->interpret(new AgentMessage('local-test', 'semantic-price', 'semantic-price-2', 'Atualiza o catupiry para R$ 40.', metadata: ['fake_intent' => [
            'tool' => 'purchases.documents.create',
            'fields' => ['items' => [['ingredient_name' => 'catupiry', 'price_paid' => '40.00']]],
        ]]), ['purchases.documents.create'], $user);

        $this->assertSame('ambiguous', $ambiguous->fields['items'][0]['_ingredient_match']['status']);
        $this->assertArrayNotHasKey('ingredient_id', $ambiguous->fields['items'][0]);
        $this->assertDatabaseCount('ingredient_prices', 0);
    }

    private function pricedRequeijao(string $brand, string $baseUnitCost, Supplier $supplier): Ingredient
    {
        $ingredient = Ingredient::query()->create(['name' => 'Requeijão', 'brand' => $brand, 'base_unit' => 'g', 'active' => true]);
        $ingredient->prices()->create([
            'supplier_id' => $supplier->id,
            'purchase_quantity' => '1',
            'purchase_unit' => 'kg',
            'normalized_quantity' => '1000',
            'price_paid' => '10',
            'base_unit_cost' => $baseUnitCost,
            'effective_date' => now()->toDateString(),
            'is_current' => true,
        ]);

        return $ingredient;
    }
}
