<?php

namespace Tests\Feature;

use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseDocumentImportService;
use App\Services\PurchaseDocumentInterpretationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PurchaseDocumentVisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $location;

    private Supplier $supplier;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->location = Location::query()->create(['name' => 'Fábrica', 'type' => 'production', 'active' => true]);
        $this->supplier = Supplier::query()->create(['name' => 'Dom Armando', 'active' => true]);
        $this->ingredient = Ingredient::query()->create(['name' => 'Muçarela', 'base_unit' => 'g', 'active' => true]);
    }

    public function test_photo_is_a_reviewable_hypothesis_and_confirmation_is_the_only_write_boundary(): void
    {
        $this->fakePurchaseInterpretation();
        $import = app(PurchaseDocumentImportService::class)->upload([$this->jpeg()], $this->location->id, $this->user);

        $this->assertSame('needs_review', $import->status);
        $this->assertDatabaseCount('purchase_documents', 0);
        $this->assertDatabaseCount('ingredient_prices', 0);
        $item = $import->items->sole();
        app(PurchaseDocumentImportService::class)->revise($import, $this->reviewPayload($item->id, false), $this->user);
        $document = app(PurchaseDocumentImportService::class)->confirm($import->fresh(), $this->user);

        $this->assertDatabaseHas('purchase_documents', ['id' => $document->id, 'source' => 'vision_import']);
        $this->assertDatabaseHas('ingredient_prices', ['purchase_document_id' => $document->id, 'base_unit_cost' => '0.04400000']);
        $this->assertDatabaseCount('ingredient_stock_movements', 0);
        $this->assertDatabaseHas('supplier_ingredient_mappings', ['supplier_id' => $this->supplier->id, 'ingredient_id' => $this->ingredient->id]);
        $this->assertSame($document->id, app(PurchaseDocumentImportService::class)->confirm($import->fresh(), $this->user)->id);
        $this->assertDatabaseCount('purchase_documents', 1);
    }

    public function test_explicit_receipt_moves_stock_and_duplicate_photo_reuses_interpretation_without_second_ai_call(): void
    {
        $this->fakePurchaseInterpretation(1);
        $service = app(PurchaseDocumentImportService::class);
        $first = $service->upload([$this->jpeg()], $this->location->id, $this->user);
        $same = $service->upload([$this->jpeg()], $this->location->id, $this->user);
        $this->assertSame($first->id, $same->id);
        $item = $first->items->sole();
        $service->revise($first, $this->reviewPayload($item->id, true), $this->user);
        $service->confirm($first->fresh(), $this->user);
        $this->assertDatabaseHas('ingredient_stock_movements', ['ingredient_id' => $this->ingredient->id, 'quantity_delta' => '5000.000000']);
        $this->assertSame('official', $first->attachments->first()->fresh()->retention_type);
    }

    public function test_production_board_is_not_misclassified_as_purchase_and_cancel_never_writes(): void
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('interpret')->once()->andReturn(AiInterpretation::fromArray(['intent' => 'production', 'tool' => null, 'confidence' => '0.99', 'fields' => ['items' => []], 'missing_fields' => [], 'source_type' => 'image', 'document_type' => 'production_board', 'summary' => 'Quadro.']));
        $this->app->instance(AiProviderInterface::class, $provider);
        $import = app(PurchaseDocumentImportService::class)->upload([$this->jpeg('board.jpg')], $this->location->id, $this->user);
        $this->assertSame('needs_review', $import->status);
        $this->assertSame('production_board', $import->document_type);
        app(PurchaseDocumentImportService::class)->cancel($import, $this->user);
        $this->assertDatabaseCount('purchase_documents', 0);
        $this->assertDatabaseCount('ingredient_prices', 0);
    }

    public function test_purchase_review_and_cost_pages_are_protected_and_available_to_the_superadministrator(): void
    {
        $this->get(route('purchase-imports.index'))->assertRedirect(route('login'));
        $this->get(route('purchase-imports.create'))->assertRedirect(route('login'));
        $this->get(route('costs.index'))->assertRedirect(route('login'));

        $this->user->update(['is_super_admin' => true]);

        $this->actingAs($this->user)->get(route('purchase-imports.index'))->assertOk();
        $this->actingAs($this->user)->get(route('purchase-imports.create'))->assertOk();
        $this->actingAs($this->user)->get(route('costs.index'))->assertOk();
    }

    public function test_unauthorized_upload_is_blocked_before_storage_and_ai_interpretation(): void
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldNotReceive('interpret');
        $this->app->instance(AiProviderInterface::class, $provider);
        $user = User::factory()->unprivileged()->create();

        try {
            app(PurchaseDocumentImportService::class)->upload([$this->jpeg()], $this->location->id, $user);
            $this->fail('O envio sem permissão deveria ter sido bloqueado.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('agent_attachments', 0);
            $this->assertDatabaseCount('purchase_document_imports', 0);
        }
    }

    public function test_incompatible_item_dimension_remains_ambiguous_instead_of_becoming_confirmable(): void
    {
        $interpretation = AiInterpretation::fromArray([
            'intent' => 'purchase',
            'tool' => 'purchases.documents.create',
            'confidence' => '0.95',
            'fields' => [
                'document_type' => 'purchase_invoice', 'supplier_id' => $this->supplier->id,
                'issue_date' => now()->toDateString(), 'total_amount' => '10',
                'items' => [['ingredient_id' => $this->ingredient->id, 'description' => 'Item', 'quantity' => '1', 'unit' => 'l', 'net_amount' => '10']],
            ],
            'missing_fields' => [], 'source_type' => 'image', 'document_type' => 'purchase_invoice', 'summary' => 'Revisão.',
        ]);

        $normalized = app(PurchaseDocumentInterpretationService::class)->normalize($interpretation);

        $this->assertContains('items.1.unit', $normalized['ambiguous_fields']);
        $this->assertNull($normalized['items'][0]['normalized_quantity']);
    }

    private function fakePurchaseInterpretation(?int $times = null): void
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $expectation = $provider->shouldReceive('interpret');
        $times === null ? $expectation->once() : $expectation->times($times);
        $expectation->andReturn(AiInterpretation::fromArray(['intent' => 'purchase', 'tool' => 'purchases.documents.create', 'confidence' => '0.98', 'fields' => ['supplier_name' => 'Dom Armando', 'document_type' => 'purchase_invoice', 'document_number' => 'NF-1', 'issue_date' => '2026-08-16', 'gross_amount' => '220', 'discount_amount' => '0', 'freight_amount' => '0', 'other_charges_amount' => '0', 'total_amount' => '220', 'items' => [['external_code' => 'MUC-1', 'description' => 'Muçarela', 'quantity' => '5', 'unit' => 'kg', 'gross_amount' => '220', 'net_amount' => '220']]], 'missing_fields' => [], 'source_type' => 'image', 'document_type' => 'purchase_invoice', 'summary' => 'Nota para revisão.']));
        $this->app->instance(AiProviderInterface::class, $provider);
    }

    private function reviewPayload(int $itemId, bool $received): array
    {
        return ['supplier_id' => $this->supplier->id, 'document_type' => 'purchase_invoice', 'document_number' => 'NF-1', 'series' => null, 'access_key' => null, 'issue_date' => '2026-08-16', 'gross_amount' => '220.00', 'discount_amount' => '0.00', 'freight_amount' => '0.00', 'other_charges_amount' => '0.00', 'total_amount' => '220.00', 'received' => $received, 'received_date' => '2026-08-16', 'items' => [$itemId => ['ingredient_id' => $this->ingredient->id, 'description' => 'Muçarela', 'external_code' => 'MUC-1', 'quantity' => '5', 'unit' => 'kg', 'gross_amount' => '220.00', 'discount_amount' => '0.00', 'freight_amount' => '0.00', 'other_charges_amount' => '0.00', 'net_amount' => '220.00']]];
    }

    private function jpeg(string $name = 'nota.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true));
    }
}
