<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\GrandChefGraphqlRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class GrandChefGraphqlClient
{
    public function execute(PdvConnection $connection, GrandChefGraphqlRequest $request): array
    {
        [$endpoint, $bearerToken, $deviceToken] = $this->credentials($connection);
        $attempts = max(1, (int) config('pdv.grandchef.max_attempts', 2));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->withHeaders(['Authorization' => "Bearer {$bearerToken}, Device {$deviceToken}"])
                    ->timeout((int) config('pdv.grandchef.timeout_seconds', 10))
                    ->post($endpoint, array_filter([
                        'query' => $request->query,
                        'variables' => $request->variables,
                        'operationName' => $request->operationName,
                    ], fn ($value): bool => $value !== null));
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new GrandChefRequestException('connection_timeout', 'O GrandChef não respondeu dentro do tempo esperado.', true);
            }

            if ($response->serverError() && $attempt < $attempts) {
                continue;
            }

            return $this->validatedData($response);
        }

        throw new GrandChefRequestException('connection_failed', 'Não foi possível consultar o GrandChef.', true);
    }

    /** @return array{0:string,1:string,2:string} */
    private function credentials(PdvConnection $connection): array
    {
        if ($connection->location_id === null || ! $connection->location()->exists()) {
            throw new IntegrationNotConfiguredException('A conexão GrandChef precisa estar vinculada a uma unidade válida.');
        }

        $endpoint = trim((string) data_get($connection->configuration, 'endpoint'));
        $bearerToken = $this->normalizeCredential((string) data_get($connection->encrypted_credentials, 'bearer_token'), 'Bearer');
        $deviceToken = $this->normalizeCredential((string) data_get($connection->encrypted_credentials, 'device_token'), 'Device');
        $parts = parse_url($endpoint);

        if ($endpoint === '' || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new IntegrationNotConfiguredException('O endpoint HTTPS do GrandChef não está configurado corretamente.');
        }

        if ($bearerToken === '') {
            throw new IntegrationNotConfiguredException('A credencial Bearer do GrandChef não está configurada.');
        }

        if ($deviceToken === '') {
            throw new IntegrationNotConfiguredException('A credencial Device do GrandChef não está configurada.');
        }

        return [$endpoint, $bearerToken, $deviceToken];
    }

    private function normalizeCredential(string $credential, string $prefix): string
    {
        return trim((string) preg_replace('/^'.preg_quote($prefix, '/').'\s+/i', '', trim($credential)));
    }

    private function validatedData(Response $response): array
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new GrandChefRequestException('authentication_rejected', 'O GrandChef rejeitou a credencial configurada.');
        }

        if ($response->serverError()) {
            throw new GrandChefRequestException('remote_unavailable', 'O GrandChef está temporariamente indisponível.', true);
        }

        if (! $response->successful()) {
            throw new GrandChefRequestException('http_error', 'O GrandChef recusou a solicitação.');
        }

        try {
            $payload = $response->json();
        } catch (Throwable) {
            throw new GrandChefRequestException('invalid_response', 'O GrandChef retornou uma resposta inválida.');
        }

        if (! is_array($payload)) {
            throw new GrandChefRequestException('invalid_response', 'O GrandChef retornou uma resposta inválida.');
        }

        if (! empty($payload['errors'])) {
            throw new GrandChefRequestException('graphql_error', 'O GrandChef retornou um erro GraphQL.');
        }

        if (! isset($payload['data']) || ! is_array($payload['data']) || $payload['data'] === []) {
            throw new GrandChefRequestException('empty_response', 'O GrandChef não retornou os dados mínimos esperados.');
        }

        return $payload['data'];
    }
}
