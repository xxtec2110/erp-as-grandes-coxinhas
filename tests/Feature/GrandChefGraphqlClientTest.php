<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\PdvConnection;
use App\Models\User;
use App\Pdv\Data\GrandChefGraphqlRequest;
use App\Pdv\GrandChefGraphqlClient;
use App\Pdv\GrandChefQueryContract;
use App\Pdv\GrandChefRequestException;
use App\Pdv\IntegrationNotConfiguredException;
use App\Services\PdvConnectionTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGrandChefQueryContract;
use Tests\TestCase;

class GrandChefGraphqlClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_unit_uses_only_its_own_endpoint_and_combined_credentials(): void
    {
        [$ibira, $catanduva] = $this->connections();
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = ['url' => $request->url(), 'authorization' => $request->header('Authorization')[0] ?? null];

            return Http::response(['data' => ['fixture' => ['connection' => 'accepted']]]);
        });

        $client = app(GrandChefGraphqlClient::class);
        $client->execute($ibira, new GrandChefGraphqlRequest('TEST_ONLY'));
        $client->execute($catanduva, new GrandChefGraphqlRequest('TEST_ONLY'));

        $this->assertSame([
            ['url' => 'https://ibira.invalid/graphql', 'authorization' => 'Bearer ibira-secret, Device ibira-device-secret'],
            ['url' => 'https://catanduva.invalid/graphql', 'authorization' => 'Bearer catanduva-secret, Device catanduva-device-secret'],
        ], $sent);
        Http::assertSent(fn (Request $request): bool => count($request->header('Authorization')) === 1
            && ! $request->hasHeader('Cookie')
            && ! $request->hasHeader('X-Socket-Id')
            && ! $request->hasHeader('Origin')
            && ! $request->hasHeader('Referer')
            && ! $request->hasHeader('X-App-Version'));
    }

    #[DataProvider('credentialPrefixProvider')]
    public function test_optional_stored_prefixes_are_normalized_into_exactly_one_combined_authorization_header(string $bearer, string $device): void
    {
        [$connection] = $this->connections();
        $connection->update(['encrypted_credentials' => ['bearer_token' => $bearer, 'device_token' => $device]]);
        Http::fake(fn (Request $request) => Http::response(['data' => ['fixture' => ['connection' => 'accepted']]]));

        app(GrandChefGraphqlClient::class)->execute($connection->fresh(), new GrandChefGraphqlRequest('TEST_ONLY'));

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer bearer-value, Device device-value']);
    }

    public static function credentialPrefixProvider(): array
    {
        return [
            'both raw' => ['bearer-value', 'device-value'],
            'bearer prefixed' => ['Bearer bearer-value', 'device-value'],
            'device prefixed' => ['bearer-value', 'Device device-value'],
            'both prefixed with mixed case' => ['bEaReR bearer-value', 'dEvIcE device-value'],
        ];
    }

    public function test_missing_device_credential_blocks_the_request_before_http_transport(): void
    {
        [$connection] = $this->connections();
        $connection->update(['encrypted_credentials' => ['bearer_token' => 'ibira-secret']]);
        Http::preventStrayRequests();

        $this->expectException(IntegrationNotConfiguredException::class);
        $this->expectExceptionMessage('A credencial Device do GrandChef não está configurada.');

        app(GrandChefGraphqlClient::class)->execute($connection->fresh(), new GrandChefGraphqlRequest('TEST_ONLY'));
    }

    public function test_successful_real_shape_transport_updates_only_safe_connection_metadata(): void
    {
        [$connection] = $this->connections();
        $this->app->singleton(GrandChefQueryContract::class, FakeGrandChefQueryContract::class);
        Http::fake(['ibira.invalid/*' => Http::response(['data' => ['fixture' => ['connection' => 'accepted']]])]);
        $user = User::factory()->create();

        app(PdvConnectionTestService::class)->test($connection, $user);

        $connection->refresh();
        $this->assertSame('healthy', $connection->status);
        $this->assertNotNull($connection->last_attempt_at);
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error_code);
        $this->assertDatabaseHas('pdv_integration_events', ['pdv_connection_id' => $connection->id, 'event_type' => 'connection_tested', 'status' => 'success']);
    }

    #[DataProvider('unsafeResponseProvider')]
    public function test_http_and_graphql_failures_use_sanitized_errors(int $status, array|string $body, string $expectedCode): void
    {
        [$connection] = $this->connections();
        Http::fake(['*' => Http::response($body, $status)]);

        try {
            app(GrandChefGraphqlClient::class)->execute($connection, new GrandChefGraphqlRequest('TEST_ONLY'));
            $this->fail('Era esperada uma falha segura do cliente GrandChef.');
        } catch (GrandChefRequestException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
            $this->assertStringNotContainsString('ibira-secret', $exception->getMessage());
            $this->assertStringNotContainsString('ibira-device-secret', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-provider-detail', $exception->getMessage());
        }
    }

    public static function unsafeResponseProvider(): array
    {
        return [
            'unauthorized' => [401, ['message' => 'sensitive-provider-detail'], 'authentication_rejected'],
            'forbidden' => [403, ['message' => 'sensitive-provider-detail'], 'authentication_rejected'],
            'server error' => [500, ['message' => 'sensitive-provider-detail'], 'remote_unavailable'],
            'graphql error' => [200, ['errors' => [['message' => 'sensitive-provider-detail']]], 'graphql_error'],
            'invalid json' => [200, 'not-json', 'invalid_response'],
            'empty data' => [200, ['data' => []], 'empty_response'],
        ];
    }

    public function test_timeout_and_server_error_retry_are_bounded(): void
    {
        [$connection] = $this->connections();
        Http::fakeSequence()->pushFailedConnection('timeout')->pushFailedConnection('timeout');

        try {
            app(GrandChefGraphqlClient::class)->execute($connection, new GrandChefGraphqlRequest('TEST_ONLY'));
            $this->fail('Era esperado timeout.');
        } catch (GrandChefRequestException $exception) {
            $this->assertSame('connection_timeout', $exception->errorCode);
            $this->assertTrue($exception->retryable);
        }
        Http::assertSentCount(2);
    }

    /** @return array{0:PdvConnection,1:PdvConnection} */
    private function connections(): array
    {
        $ibiraLocation = Location::query()->create(['name' => 'Unidade Ibirá', 'type' => 'store', 'active' => true]);
        $catanduvaLocation = Location::query()->create(['name' => 'Catanduva', 'type' => 'store', 'active' => true]);
        $legacy = PdvConnection::query()->firstOrFail();
        $legacy->update([
            'location_id' => $ibiraLocation->id,
            'name' => 'GrandChef Ibirá',
            'enabled' => true,
            'configuration' => ['endpoint' => 'https://ibira.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'ibira-secret', 'device_token' => 'ibira-device-secret'],
        ]);
        $catanduva = PdvConnection::query()->create([
            'location_id' => $catanduvaLocation->id,
            'provider' => 'grandchef',
            'name' => 'GrandChef Catanduva',
            'enabled' => true,
            'configuration' => ['endpoint' => 'https://catanduva.invalid/graphql'],
            'encrypted_credentials' => ['bearer_token' => 'catanduva-secret', 'device_token' => 'catanduva-device-secret'],
        ]);

        return [$legacy->fresh('location'), $catanduva->fresh('location')];
    }
}
