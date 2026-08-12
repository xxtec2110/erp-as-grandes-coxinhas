<?php

namespace App\WhatsApp;

use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\AgentCostService;
use App\Services\AgentEventService;
use Throwable;

class WhatsAppOutboundService
{
    public function __construct(private WhatsAppClientInterface $client, private AgentEventService $events, private AgentCostService $costs) {}

    public function sendText(string $recipient, string $text, WhatsAppWebhookEvent $event): WhatsAppOutboundMessage
    {
        $outbound = WhatsAppOutboundMessage::query()->firstOrCreate([
            'whatsapp_webhook_event_id' => $event->id,
        ], [
            'recipient' => $recipient,
            'message_type' => 'text',
            'body' => $text,
        ]);
        if (in_array($outbound->status, ['sent', 'delivered', 'read'], true)) {
            return $outbound;
        }
        $attempt = $outbound->attempts + 1;
        $outbound->update(['attempts' => $attempt, 'status' => 'sending']);
        try {
            $providerId = $this->client->sendText($recipient, $text);
            $outbound->update(['provider_message_id' => $providerId, 'status' => 'sent', 'sent_at' => now(), 'error_code' => null]);
            $this->events->record('whatsapp_response_sent', 'whatsapp', status: 'sent', metadata: ['outbound_message_id' => $outbound->id, 'attempt' => $attempt]);
            $this->costs->record('meta', 'meta_outbound', 'meta-outbound:'.$providerId);

            return $outbound->refresh();
        } catch (Throwable $exception) {
            $code = class_basename($exception);
            $outbound->update(['status' => 'retrying', 'error_code' => $code]);
            $this->events->record('whatsapp_send_error', 'whatsapp', status: 'retrying', errorCode: $code, metadata: ['outbound_message_id' => $outbound->id, 'attempt' => $attempt]);

            throw new TransientWhatsAppException($code, previous: $exception);
        }
    }

    public function applyStatus(array $status): void
    {
        $outbound = WhatsAppOutboundMessage::query()->where('provider_message_id', $status['provider_message_id'])->first();
        if ($outbound !== null) {
            $outbound->update(['status' => $status['status'], 'error_code' => $status['error_code'] ?? null]);
        }
        $this->events->record('whatsapp_status_received', 'whatsapp', status: $status['status'], errorCode: isset($status['error_code']) ? (string) $status['error_code'] : null, metadata: ['outbound_message_id' => $outbound?->id]);
    }
}
