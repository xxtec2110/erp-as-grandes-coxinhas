<?php

namespace App\Jobs;

use App\Services\AgentEventService;
use App\WhatsApp\WhatsAppChannelAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload) {}

    public function handle(WhatsAppChannelAdapter $adapter): void
    {
        $attempt = $this->attempts();
        $backoff = $this->backoff();
        $adapter->handle(
            $this->payload,
            $attempt,
            $attempt >= $this->tries(),
            $backoff[min($attempt - 1, count($backoff) - 1)],
        );
    }

    public function tries(): int
    {
        return max(1, (int) config('whatsapp.max_send_attempts', 3));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function failed(Throwable $exception): void
    {
        app(AgentEventService::class)->record(
            'whatsapp_processing_failed',
            'whatsapp',
            status: 'failed',
            errorCode: class_basename($exception),
        );
    }
}
