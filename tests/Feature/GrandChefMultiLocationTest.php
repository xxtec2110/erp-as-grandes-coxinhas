<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\PdvLocationMapping;
use App\Models\PdvProductMapping;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Pdv\IntegrationNotConfiguredException;
use App\Services\PdvConnectionService;
use App\Services\PdvConnectionTestService;
use App\Services\PdvInboundService;
use App\Services\PdvSyncService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrandChefMultiLocationTest extends TestCase
{
    use RefreshDatabase;

    private Location $ibira;

    private Location $catanduva;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $this->ibira = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $this->catanduva = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        Location::query()->create(['name' => 'Fábrica Central', 'type' => 'production', 'active' => true]);
        $this->admin = User::factory()->create(['is_super_admin' => true]);
    }

    public function test_connections_are_independent_and_secret_is_encrypted_hidden_and_never_rendered(): void
    {
        $connections = app(PdvConnectionService::class);
        $ibiraConnection = $connections->create($this->ibira, $this->payload('Ibirá', 'https://ibira.invalid/graphql', 'ibira-token', 'ibira-device'), $this->admin);
        $catanduvaConnection = $connections->create($this->catanduva, $this->payload('Catanduva', 'https://catanduva.invalid/graphql', 'catanduva-token', 'catanduva-device', $this->catanduva), $this->admin);

        $this->assertNotSame($ibiraConnection->id, $catanduvaConnection->id);
        $this->assertSame($this->ibira->id, $ibiraConnection->location_id);
        $this->assertSame($this->catanduva->id, $catanduvaConnection->location_id);
        $this->assertStringNotContainsString('ibira-token', (string) DB::table('pdv_connections')->where('id', $ibiraConnection->id)->value('encrypted_credentials'));
        $this->assertStringNotContainsString('ibira-device', (string) DB::table('pdv_connections')->where('id', $ibiraConnection->id)->value('encrypted_credentials'));
        $this->assertArrayNotHasKey('encrypted_credentials', $ibiraConnection->toArray());
        $this->actingAs($this->admin)
            ->get(route('pdv.connections.edit', $ibiraConnection))
            ->assertOk()
            ->assertDontSee('ibira-token')
            ->assertDontSee('ibira-device')
            ->assertSee('Bearer configurado')
            ->assertSee('Device configurado');
    }

    public function test_bearer_and_device_are_normalized_replaced_and_preserved_independently(): void
    {
        $service = app(PdvConnectionService::class);
        $connection = $service->create($this->ibira, $this->payload('Ibirá', 'https://ibira.invalid/graphql', 'Bearer original-token', 'Device original-device'), $this->admin);
        $this->assertSame('original-token', data_get($connection->encrypted_credentials, 'bearer_token'));
        $this->assertSame('original-device', data_get($connection->encrypted_credentials, 'device_token'));

        $service->update($connection, $this->payload('Ibirá atualizada', 'https://ibira.invalid/graphql', null, null), $this->admin);
        $this->assertSame('original-token', data_get($connection->fresh()->encrypted_credentials, 'bearer_token'));
        $this->assertSame('original-device', data_get($connection->fresh()->encrypted_credentials, 'device_token'));

        $service->update($connection->fresh(), $this->payload('Ibirá atualizada', 'https://ibira.invalid/graphql', 'Bearer replacement-token', null), $this->admin);
        $this->assertSame('replacement-token', data_get($connection->fresh()->encrypted_credentials, 'bearer_token'));
        $this->assertSame('original-device', data_get($connection->fresh()->encrypted_credentials, 'device_token'));

        $service->update($connection->fresh(), $this->payload('Ibirá atualizada', 'https://ibira.invalid/graphql', null, 'Device replacement-device'), $this->admin);
        $this->assertSame('replacement-token', data_get($connection->fresh()->encrypted_credentials, 'bearer_token'));
        $this->assertSame('replacement-device', data_get($connection->fresh()->encrypted_credentials, 'device_token'));

        $this->actingAs($this->admin)->put(route('pdv.connections.update', $connection), [
            'location_id' => $this->ibira->id,
            'name' => 'GrandChef Ibirá via web',
            'endpoint' => 'https://ibira.invalid/graphql',
            'bearer_token' => '',
            'device_token' => 'Device web-device',
            'enabled' => '1',
        ])->assertRedirect(route('pdv.index'));
        $this->assertSame('replacement-token', data_get($connection->fresh()->encrypted_credentials, 'bearer_token'));
        $this->assertSame('web-device', data_get($connection->fresh()->encrypted_credentials, 'device_token'));
    }

    public function test_restricted_user_only_sees_and_manages_an_authorized_unit_while_master_sees_all(): void
    {
        $ibiraConnection = $this->connection($this->ibira, 'ibira');
        $catanduvaConnection = $this->connection($this->catanduva, 'catanduva');
        $restricted = User::factory()->unprivileged()->create();
        $restricted->permissions()->attach(Permission::query()->where('name', 'pdv.manage')->firstOrFail(), ['allowed' => true]);
        $restricted->locations()->attach($this->ibira);

        $this->actingAs($restricted)->get(route('pdv.index'))->assertOk()->assertSee('Unidade Ibirá')->assertDontSee('Catanduva');
        $this->actingAs($restricted)->get(route('pdv.connections.edit', $ibiraConnection))->assertOk();
        $this->actingAs($restricted)->get(route('pdv.connections.edit', $catanduvaConnection))->assertForbidden();
        $this->actingAs($restricted)->get(route('pdv.reports.sales', $catanduvaConnection))->assertForbidden();

        $this->actingAs($this->admin)->get(route('pdv.index'))->assertOk()->assertSee('Unidade Ibirá')->assertSee('Catanduva')->assertSee('Fábrica Central');
    }

    public function test_connection_without_location_cannot_be_activated_tested_or_synchronized(): void
    {
        $legacy = PdvConnection::query()->firstOrFail();
        $legacy->update(['enabled' => true, 'configuration' => ['endpoint' => 'https://legacy.invalid/graphql'], 'encrypted_credentials' => ['bearer_token' => 'legacy-token', 'device_token' => 'legacy-device']]);
        Http::preventStrayRequests();

        try {
            app(PdvConnectionTestService::class)->test($legacy, $this->admin);
            $this->fail('A conexão legada não poderia ser testada.');
        } catch (IntegrationNotConfiguredException $exception) {
            $this->assertStringContainsString('unidade ativa', $exception->getMessage());
        }

        config(['pdv.enabled' => true, 'pdv.sync_enabled' => true]);
        $this->expectException(IntegrationNotConfiguredException::class);
        app(PdvSyncService::class)->sync($legacy, $this->admin);
    }

    public function test_activation_requires_endpoint_token_and_store_location(): void
    {
        try {
            app(PdvConnectionService::class)->create($this->ibira, $this->payload('Ibirá', 'https://ibira.invalid/graphql', null, 'device'), $this->admin);
            $this->fail('Não deveria ativar sem token.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enabled', $exception->errors());
        }

        try {
            app(PdvConnectionService::class)->create($this->ibira, $this->payload('Ibirá', 'https://ibira.invalid/graphql', 'token', null), $this->admin);
            $this->fail('Não deveria ativar sem Device.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enabled', $exception->errors());
        }

        $factory = Location::query()->where('type', 'production')->firstOrFail();
        $this->expectException(ValidationException::class);
        app(PdvConnectionService::class)->create($factory, $this->payload('Fábrica', 'https://factory.invalid/graphql', 'token', 'device', $factory), $this->admin);
    }

    public function test_cross_location_mapping_is_blocked_in_backend(): void
    {
        $connection = $this->connection($this->ibira, 'ibira');
        $mapping = PdvLocationMapping::query()->create(['pdv_connection_id' => $connection->id, 'external_location_id' => 'EXT-IBIRA', 'external_name' => 'Ibirá', 'location_id' => $this->ibira->id, 'status' => 'confirmed']);

        $this->actingAs($this->admin)->put(route('pdv.mappings.update', $connection), ['mapping_type' => 'location', 'mapping_id' => $mapping->id, 'target_id' => $this->catanduva->id])->assertStatus(422);
        $this->assertSame($this->ibira->id, $mapping->fresh()->location_id);
    }

    public function test_same_external_ids_are_isolated_by_connection(): void
    {
        $ibiraConnection = $this->connection($this->ibira, 'ibira');
        $catanduvaConnection = $this->connection($this->catanduva, 'catanduva');
        $product = Product::query()->create(['name' => 'Coxinha', 'stock_unit' => 'un', 'active' => true]);

        foreach ([$ibiraConnection, $catanduvaConnection] as $connection) {
            PdvProductMapping::query()->create(['pdv_connection_id' => $connection->id, 'external_product_id' => 'SAME-ID', 'external_name' => 'Coxinha', 'product_id' => $product->id, 'status' => 'confirmed']);
        }

        $this->assertDatabaseCount('pdv_product_mappings', 2);
    }

    public function test_sensitive_payload_keys_are_removed_recursively(): void
    {
        $connection = $this->connection($this->ibira, 'ibira');
        $event = app(PdvInboundService::class)->receive($connection, 'evt-safe', 'fixture', [
            'authorization' => 'Bearer top-secret',
            'device' => 'device-secret',
            'nested' => ['cookie' => 'secret-cookie', 'safe' => 'visible', 'deeper' => ['access_token' => 'hidden', 'device_token' => 'hidden-device']],
        ]);

        $this->assertSame(['nested' => ['safe' => 'visible', 'deeper' => []]], $event->payload);
        $this->assertStringNotContainsString('top-secret', (string) DB::table('pdv_inbound_events')->where('id', $event->id)->value('payload'));
        $this->assertStringNotContainsString('device-secret', (string) DB::table('pdv_inbound_events')->where('id', $event->id)->value('payload'));
    }

    public function test_legacy_url_redirects_to_the_canonical_grandchef_area(): void
    {
        $this->actingAs($this->admin)->get('/configuracoes/integracoes/pdv')->assertRedirect(route('pdv.index'));
        $this->assertSame('/configuracoes/integracoes/grandchef', route('pdv.index', absolute: false));
    }

    public function test_not_configured_connection_test_is_never_presented_as_success(): void
    {
        $connection = $this->connection($this->ibira, 'ibira');
        $connection->update(['encrypted_credentials' => null]);

        $this->actingAs($this->admin)
            ->post(route('pdv.test', $connection))
            ->assertRedirect()
            ->assertSessionHas('error', 'A credencial Bearer do GrandChef não está configurada.')
            ->assertSessionMissing('success');

        $this->assertSame('not_configured', $connection->fresh()->status);
    }

    private function connection(Location $location, string $suffix): PdvConnection
    {
        return PdvConnection::query()->create([
            'location_id' => $location->id,
            'provider' => 'grandchef',
            'name' => 'GrandChef '.$location->name,
            'status' => 'configured',
            'enabled' => true,
            'configuration' => ['endpoint' => "https://{$suffix}.invalid/graphql"],
            'encrypted_credentials' => ['bearer_token' => $suffix.'-token', 'device_token' => $suffix.'-device'],
            'created_by' => $this->admin->id,
        ]);
    }

    private function payload(string $name, string $endpoint, ?string $token, ?string $deviceToken, ?Location $location = null): array
    {
        return ['location_id' => ($location ?? $this->ibira)->id, 'name' => 'GrandChef '.$name, 'endpoint' => $endpoint, 'bearer_token' => $token, 'device_token' => $deviceToken, 'enabled' => true];
    }
}
