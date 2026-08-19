<?php

namespace Tests\Feature;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentResponse;
use App\Agent\ErpAgentService;
use App\Models\AgentAttachment;
use App\Models\AgentEvent;
use App\Models\AgentUsageCost;
use App\Models\Ingredient;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionSubmission;
use App\Models\ProductionUserPolicy;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\IngredientPriceService;
use App\Services\IngredientStockService;
use App\Services\ProductionNotificationService;
use App\Services\ProductionSubmissionService;
use App\Services\ProductMatchService;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppClientInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestrictedProductionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $user;

    private UserExternalIdentity $identity;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 20:00:00', config('app.timezone')));

        $this->location = Location::query()->create(['name' => 'Fábrica Ibirá', 'type' => 'production', 'active' => true]);
        [$this->user, $this->identity] = $this->restrictedUser('restricted-board-1', $this->location);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_restricted_profile_blocks_text_without_ai(): void
    {
        $response = $this->agent()->handle(new AgentMessage('local', $this->identity->external_user_id, 'restricted-text-1', 'Quanto vendeu?'));

        $this->assertFalse($response->success);
        $this->assertSame('restricted_production_profile', $response->errorCode);
        $this->assertDatabaseHas('agent_events', ['event_type' => 'restricted_production_message_blocked']);
        $this->assertDatabaseMissing('agent_events', ['event_type' => 'ai_provider_selected']);
    }

    public function test_valid_board_uses_exact_names_and_aliases_and_only_creates_preview(): void
    {
        [$frango, $costela, $alcatra] = $this->boardProducts();
        $frango->aliases()->create([
            'name' => 'Frango Catupiry',
            'normalized_name' => app(ProductMatchService::class)->normalize('Frango Catupiry'),
        ]);

        [$response] = $this->sendBoard($this->validFields([
            ['product_name' => 'Frango Catupiry', 'quantity' => 48, 'quantity_status' => 'clear'],
            ['product_name' => $costela->name, 'quantity' => 150, 'quantity_status' => 'clear'],
            ['product_name' => $alcatra->name, 'quantity' => 32, 'quantity_status' => 'clear'],
        ]), confidence: '0.20');

        $this->assertTrue($response->success);
        $this->assertSame('confirmation', $response->responseType);
        $this->assertSame(230, $response->data['total']);
        $this->assertStringContainsString('PRODUÇÃO IDENTIFICADA', $response->message);
        $this->assertStringContainsString('Data: 15/08/2026', $response->message);
        $this->assertStringContainsString('Unidade: FÁBRICA IBIRÁ', $response->message);
        $this->assertStringContainsString('Frango com Catupiry: 48', $response->message);
        $this->assertStringContainsString('TOTAL: 230', $response->message);
        $this->assertDatabaseHas('production_submissions', ['status' => 'awaiting_confirmation']);
        $this->assertDatabaseHas('pending_agent_actions', ['status' => 'pending', 'tool_name' => 'production.orders.complete_batch']);
        $this->assertNotNull(ProductionSubmission::query()->firstOrFail()->pendingAction->expires_at);
        $this->assertDatabaseCount('production_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseMissing('ingredient_stock_movements', ['type' => 'production_consumption']);
        $this->assertSame($frango->id, data_get(ProductionSubmission::query()->firstOrFail()->interpretation, 'items.0.product_id'));
    }

    public function test_explicit_zero_is_valid_but_blank_decimal_and_ambiguous_quantities_are_rejected(): void
    {
        [$frango] = $this->boardProducts();
        [$zero] = $this->sendBoard($this->validFields([
            ['product_name' => $frango->name, 'quantity' => 0, 'quantity_status' => 'clear'],
        ]));
        $this->assertTrue($zero->success);
        $this->assertSame(0, $zero->data['total']);
        $this->assertDatabaseCount('production_orders', 0);

        $this->sendRestrictedText('2', 'cancel-zero');

        foreach ([
            ['quantity' => null, 'quantity_status' => 'missing'],
            ['quantity' => '48.5', 'quantity_status' => 'clear'],
            ['quantity' => '48 ou 43', 'quantity_status' => 'ambiguous'],
        ] as $index => $invalidQuantity) {
            [$response, $attachment] = $this->sendBoard($this->validFields([
                ['product_name' => $frango->name, ...$invalidQuantity],
            ]), messageId: 'invalid-quantity-'.$index);

            $this->assertFalse($response->success);
            $this->assertSame('production_board_invalid', $response->errorCode);
            $this->assertStringContainsString('quantidades', $response->message);
            $this->assertNull($attachment->fresh()->path);
        }
        $this->assertDatabaseCount('production_orders', 0);
    }

    public function test_missing_wrong_ambiguous_and_unreadable_visual_dates_are_rejected_even_with_exif(): void
    {
        [$frango] = $this->boardProducts();
        $cases = [
            ['production_date' => null, 'date_status' => 'missing'],
            ['production_date' => '2026-08-14', 'date_status' => 'clear'],
            ['production_date' => '15/08 ou 16/08', 'date_status' => 'ambiguous'],
            ['production_date' => null, 'date_status' => 'unreadable'],
        ];

        foreach ($cases as $index => $date) {
            [$response, $attachment] = $this->sendBoard([
                ...$this->validFields([['product_name' => $frango->name, 'quantity' => 48, 'quantity_status' => 'clear']]),
                ...$date,
            ], messageId: 'invalid-date-'.$index, attachmentMetadata: ['exif_date' => '2026-08-15']);

            $this->assertFalse($response->success);
            $this->assertStringContainsString('data do quadro', $response->message);
            $this->assertSame([], $attachment->fresh()->metadata);
            $this->assertNull($attachment->fresh()->path);
        }
        $this->assertDatabaseCount('production_submissions', 0);
        $this->assertDatabaseCount('pending_agent_actions', 0);
    }

    public function test_unknown_inactive_duplicate_and_incomplete_products_reject_the_whole_photo(): void
    {
        [$frango, $costela] = $this->boardProducts();
        $inactive = $this->product('Produto Inativo', false);
        $cases = [
            $this->validFields([
                ['product_name' => $frango->name, 'quantity' => 48, 'quantity_status' => 'clear'],
                ['product_name' => 'Sabor inexistente', 'quantity' => 10, 'quantity_status' => 'clear'],
            ]),
            $this->validFields([['product_name' => $inactive->name, 'quantity' => 10, 'quantity_status' => 'clear']]),
            $this->validFields([
                ['product_name' => $costela->name, 'quantity' => 10, 'quantity_status' => 'clear'],
                ['product_name' => $costela->name, 'quantity' => 20, 'quantity_status' => 'clear'],
            ]),
            [...$this->validFields([['product_name' => $frango->name, 'quantity' => 48, 'quantity_status' => 'clear']]), 'board_complete' => false],
        ];

        foreach ($cases as $index => $fields) {
            [$response] = $this->sendBoard($fields, messageId: 'invalid-product-'.$index);

            $this->assertFalse($response->success);
            $this->assertStringContainsString('Nenhuma informação foi registrada', $response->message);
        }

        $this->assertDatabaseCount('production_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('pending_agent_actions', 0);
    }

    public function test_sim_executes_once_without_ai_and_preserves_the_photo_as_evidence(): void
    {
        $this->boardProducts();
        [, $attachment] = $this->sendBoard($this->validBoardFields(), messageId: 'valid-before-sim');
        $usageBeforeConfirmation = AgentUsageCost::query()->count();

        $first = $this->sendRestrictedText('SIM', 'confirm-first');
        $second = $this->sendRestrictedText('1', 'confirm-duplicate');

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertDatabaseCount('production_orders', 1);
        $this->assertDatabaseCount('production_order_items', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseHas('production_submissions', ['status' => 'confirmed']);
        $this->assertDatabaseHas('pending_agent_actions', ['status' => 'executed']);
        $this->assertSame($usageBeforeConfirmation, AgentUsageCost::query()->count());
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->assertSame('official', $attachment->fresh()->retention_type);
        $this->assertSame('confirmed', $attachment->fresh()->processing_status);
    }

    public function test_no_cancels_pending_action_deletes_photo_and_prevents_later_confirmation(): void
    {
        $this->boardProducts();
        [, $attachment] = $this->sendBoard($this->validBoardFields(), messageId: 'valid-before-no');
        $path = $attachment->path;

        $response = $this->sendRestrictedText('NÃO', 'reject-photo');
        $afterNo = ProductionSubmission::query()->firstOrFail();
        $this->sendRestrictedText('SIM', 'sim-after-no');

        $this->assertTrue($response->success);
        $this->assertSame('rejected_by_user', $afterNo->status);
        $this->assertNull($afterNo->interpretation);
        $this->assertNotNull($afterNo->file_deleted_at);
        $this->assertDatabaseHas('pending_agent_actions', ['status' => 'cancelled']);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertNull($attachment->fresh()->path);
        $this->assertSame([], $attachment->fresh()->metadata);
        $this->assertDatabaseCount('production_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_free_text_and_another_user_cannot_change_or_confirm_an_existing_preview(): void
    {
        $this->boardProducts();
        $this->sendBoard($this->validBoardFields(), messageId: 'valid-owned-preview');

        $correction = $this->sendRestrictedText('corrige 48 para 58', 'restricted-correction');
        [, $otherIdentity] = $this->restrictedUser('restricted-board-2', $this->location);
        $other = $this->agent()->handle(new AgentMessage('local', $otherIdentity->external_user_id, 'other-user-sim', 'SIM'));

        $this->assertFalse($correction->success);
        $this->assertFalse($other->success);
        $this->assertDatabaseHas('pending_agent_actions', ['user_id' => $this->user->id, 'status' => 'pending']);
        $this->assertDatabaseCount('production_orders', 0);
    }

    public function test_second_photo_does_not_call_ai_while_a_preview_is_pending_and_is_deleted(): void
    {
        $this->boardProducts();
        $this->sendBoard($this->validBoardFields(), messageId: 'first-pending-photo');
        $usageBefore = AgentUsageCost::query()->count();

        [$response, $secondAttachment] = $this->sendBoard($this->validBoardFields(), messageId: 'second-pending-photo');

        $this->assertFalse($response->success);
        $this->assertSame('production_board_confirmation_pending', $response->errorCode);
        $this->assertSame($usageBefore, AgentUsageCost::query()->count());
        $this->assertNull($secondAttachment->fresh()->path);
        $this->assertDatabaseCount('pending_agent_actions', 1);
        $this->assertDatabaseCount('production_orders', 0);
    }

    public function test_retry_of_the_same_photo_keeps_the_original_pending_preview(): void
    {
        $this->boardProducts();
        [, $attachment] = $this->sendBoard($this->validBoardFields(), messageId: 'same-photo-retry');
        $usageBefore = AgentUsageCost::query()->count();

        $response = $this->agent()->handle(new AgentMessage(
            'local',
            $this->identity->external_user_id,
            'same-photo-retry',
            messageType: 'image',
            attachments: [$attachment->id],
        ));

        $this->assertTrue($response->success);
        $this->assertSame('confirmation', $response->responseType);
        $this->assertSame($usageBefore, AgentUsageCost::query()->count());
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->assertDatabaseCount('pending_agent_actions', 1);
    }

    public function test_confirmation_is_blocked_after_the_daily_cutoff(): void
    {
        $this->boardProducts();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 23:59:58', config('app.timezone')));
        $this->sendBoard($this->validBoardFields(), messageId: 'before-cutoff');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16 00:00:01', config('app.timezone')));

        $lateConfirmation = $this->sendRestrictedText('SIM', 'after-midnight');

        $this->assertFalse($lateConfirmation->success);
        $this->assertStringContainsString('prazo', mb_strtolower($lateConfirmation->message));
        $this->assertDatabaseCount('production_orders', 0);
        $this->assertDatabaseHas('pending_agent_actions', ['status' => 'pending']);
    }

    public function test_photo_submitted_after_the_configured_cutoff_is_rejected(): void
    {
        $this->boardProducts();
        ProductionUserPolicy::query()->update(['cutoff_time' => '20:00:00']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-15 20:00:01', config('app.timezone')));

        [$latePhoto] = $this->sendBoard($this->validBoardFields(), messageId: 'past-configured-cutoff');

        $this->assertFalse($latePhoto->success);
        $this->assertStringContainsString('prazo', mb_strtolower($latePhoto->message));
        $this->assertDatabaseCount('production_submissions', 0);
        $this->assertDatabaseCount('pending_agent_actions', 0);
    }

    public function test_service_rejects_confirmation_by_a_different_user(): void
    {
        $this->boardProducts();
        $this->sendBoard($this->validBoardFields(), messageId: 'service-owner-preview');
        [$other] = $this->restrictedUser('restricted-board-3', $this->location);

        $this->expectException(DomainException::class);
        app(ProductionSubmissionService::class)->confirm(ProductionSubmission::query()->firstOrFail(), $other);
    }

    public function test_missing_alert_is_sent_once_and_midnight_records_no_submission_without_zero_production(): void
    {
        $client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClientInterface::class, $client);
        $admin = User::factory()->create(['is_super_admin' => true, 'active' => true]);
        UserExternalIdentity::query()->create([
            'user_id' => $admin->id,
            'channel' => 'whatsapp',
            'external_user_id' => '5511999999999',
            'status' => 'approved',
            'active' => true,
        ]);
        $policy = ProductionUserPolicy::query()->with(['user', 'location'])->firstOrFail();
        $service = app(ProductionNotificationService::class);
        $date = CarbonImmutable::parse('2026-08-15', config('app.timezone'));

        $service->sendMissingAlert($policy, $date);
        $service->sendMissingAlert($policy, $date);
        $service->markNotSubmitted($policy, $date);
        $service->markNotSubmitted($policy, $date);

        $this->assertCount(1, $client->sent());
        $this->assertDatabaseHas('production_submissions', ['status' => 'production_not_submitted', 'alert_sent' => true]);
        $this->assertSame(1, AgentEvent::query()->where('event_type', 'production_not_submitted')->count());
        $this->assertDatabaseCount('production_orders', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function validBoardFields(): array
    {
        return $this->validFields([
            ['product_name' => 'Frango com Catupiry', 'quantity' => 48, 'quantity_status' => 'clear'],
            ['product_name' => 'Costela com Queijo', 'quantity' => 150, 'quantity_status' => 'clear'],
            ['product_name' => 'Alcatra com Provolone', 'quantity' => 32, 'quantity_status' => 'clear'],
        ]);
    }

    private function validFields(array $items): array
    {
        return [
            'production_date' => '2026-08-15',
            'date_status' => 'clear',
            'board_complete' => true,
            'items' => $items,
        ];
    }

    private function sendBoard(array $fields, ?string $messageId = null, string $confidence = '0.90', array $attachmentMetadata = []): array
    {
        $this->sequence++;
        $messageId ??= 'board-'.$this->sequence;
        $path = 'agent-attachments/test/'.$messageId.'.jpeg';
        Storage::disk('local')->put($path, "\xFF\xD8\xFFboard-{$messageId}");
        $attachment = AgentAttachment::query()->create([
            'source' => 'web_agent',
            'content_hash' => hash('sha256', 'board-'.$messageId),
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'quadro.jpeg',
            'mime_type' => 'image/jpeg',
            'size' => 32,
            'processing_status' => 'stored',
            'retention_type' => 'temporary',
            'created_by' => $this->user->id,
            'location_id' => $this->location->id,
            'metadata' => ['purpose' => 'agent', ...$attachmentMetadata],
        ]);
        $response = $this->agent()->handle(new AgentMessage(
            'local',
            $this->identity->external_user_id,
            $messageId,
            messageType: 'image',
            attachments: [$attachment->id],
            metadata: ['fake_intent' => [
                'intent' => 'production.orders.complete_batch',
                'tool' => 'production.orders.complete_batch',
                'confidence' => $confidence,
                'fields' => $fields,
                'missing_fields' => [],
                'document_type' => 'production_board',
                'summary' => 'Quadro de produção simulado.',
            ]],
        ));

        return [$response, $attachment];
    }

    private function sendRestrictedText(string $text, string $messageId): ErpAgentResponse
    {
        return $this->agent()->handle(new AgentMessage('local', $this->identity->external_user_id, $messageId, $text));
    }

    private function boardProducts(): array
    {
        return [
            $this->product('Frango com Catupiry'),
            $this->product('Costela com Queijo'),
            $this->product('Alcatra com Provolone'),
        ];
    }

    private function product(string $name, bool $active = true): Product
    {
        $product = Product::query()->firstOrCreate(['name' => $name], ['stock_unit' => 'un', 'active' => $active]);
        $recipe = $product->recipe()->firstOrCreate([], [
            'yield_quantity' => '1',
            'technical_loss_percentage' => '0',
            'packaging_cost' => '0',
        ]);
        $ingredient = Ingredient::query()->firstOrCreate(['name' => 'Insumo técnico do teste'], ['base_unit' => 'g', 'active' => true]);
        $supplier = Supplier::query()->firstOrCreate(['name' => 'Fornecedor técnico do teste'], ['active' => true]);
        if ($ingredient->currentPrice === null) {
            app(IngredientPriceService::class)->record($ingredient, ['supplier_id' => $supplier->id, 'purchase_quantity' => '1', 'purchase_unit' => 'kg', 'price_paid' => '10', 'effective_date' => now()->toDateString(), 'is_current' => true]);
        }
        $recipe->ingredients()->firstOrCreate(['ingredient_id' => $ingredient->id], ['quantity' => '0.001', 'unit' => 'g']);
        app(IngredientStockService::class)->record(['ingredient_id' => $ingredient->id, 'location_id' => $this->location->id, 'type' => 'opening_balance', 'quantity_delta' => '1000', 'operation_date' => now()->toDateString(), 'idempotency_key' => 'restricted-test-opening-stock', 'source' => 'test']);

        return $product;
    }

    private function restrictedUser(string $externalId, Location $location): array
    {
        $user = User::factory()->unprivileged()->create(['active' => true]);
        $user->locations()->sync([$location->id]);
        foreach (['agent.image.use', 'agent.write.use', 'production.orders.create', 'production.orders.complete'] as $permission) {
            $user->permissions()->attach(Permission::query()->where('name', $permission)->firstOrFail(), ['allowed' => true]);
        }
        $identity = UserExternalIdentity::query()->create([
            'user_id' => $user->id,
            'channel' => 'local',
            'external_user_id' => $externalId,
            'status' => 'approved',
            'active' => true,
            'structured_commands_allowed' => true,
            'free_chat_allowed' => false,
            'image_allowed' => true,
        ]);
        ProductionUserPolicy::query()->create([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'briefing_time' => '06:00:00',
            'alert_time' => '20:00:00',
            'cutoff_time' => '23:59:59',
            'active' => true,
            'restricted' => true,
        ]);

        return [$user, $identity];
    }

    private function agent(): ErpAgentService
    {
        return app(ErpAgentService::class);
    }
}
