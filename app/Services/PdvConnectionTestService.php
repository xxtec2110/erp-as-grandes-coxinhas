<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\User;
use App\Pdv\GrandChefRequestException;
use App\Pdv\IntegrationNotConfiguredException;
use App\Pdv\PdvProviderManager;
use Throwable;

class PdvConnectionTestService
{
    public function __construct(
        private PdvConnectionAccessService $access,
        private PdvProviderManager $providers,
        private PdvIntegrationEventService $events,
    ) {}

    public function test(PdvConnection $connection, User $user): void
    {
        $this->access->authorizeConnection($user, $connection);
        $this->access->assertOperationalScope($connection);
        $started = hrtime(true);
        $connection->update(['last_attempt_at' => now()]);

        try {
            if (! $this->providers->for($connection)->testConnection($connection)) {
                throw new GrandChefRequestException('invalid_response', 'O GrandChef não confirmou a conexão.');
            }

            $connection->update([
                'status' => 'healthy',
                'last_success_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ]);
            $this->events->record('connection_tested', $connection, user: $user, status: 'success', durationMs: $this->duration($started));
        } catch (GrandChefRequestException|IntegrationNotConfiguredException $exception) {
            $code = $exception instanceof GrandChefRequestException ? $exception->errorCode : 'not_configured';
            $connection->update([
                'status' => $code === 'not_configured' ? 'not_configured' : 'degraded',
                'last_failure_at' => now(),
                'last_error_code' => $code,
                'last_error_message' => $exception->getMessage(),
            ]);
            $this->events->record('connection_test_failed', $connection, user: $user, status: 'failed', metadata: ['error_code' => $code], durationMs: $this->duration($started));

            throw $exception;
        } catch (Throwable $exception) {
            $connection->update([
                'status' => 'degraded',
                'last_failure_at' => now(),
                'last_error_code' => 'unexpected_error',
                'last_error_message' => 'Falha inesperada ao testar a conexão.',
            ]);
            $this->events->record('connection_test_failed', $connection, user: $user, status: 'failed', metadata: ['error_code' => 'unexpected_error'], durationMs: $this->duration($started));

            throw new GrandChefRequestException('unexpected_error', 'Falha inesperada ao testar a conexão.');
        }
    }

    private function duration(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
