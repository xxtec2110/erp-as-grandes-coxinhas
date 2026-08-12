<?php

namespace App\WhatsApp;

use App\Agent\ErpAgentService;
use App\Models\AgentAttachment;
use App\Models\AgentConversationMessage;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\AgentCostService;
use App\Services\AgentEventService;
use App\Services\WhatsAppConnectionService;
use Throwable;

class WhatsAppChannelAdapter
{
    public function __construct(
        private WhatsAppMessageNormalizer $normalizer,
        private ErpAgentService $agent,
        private WhatsAppResponseFormatter $formatter,
        private WhatsAppOutboundService $outbound,
        private AgentEventService $events,
        private WhatsAppConnectionService $connection,
        private AgentCostService $costs,
        private WhatsAppFailureClassifier $failures,
    ) {}

    public function handle(array $payload, int $attempt = 1, bool $finalAttempt = false, int $retryDelay = 5): void
    {
        $normalized = $this->normalizer->normalize($payload);
        if ($normalized === []) {
            $this->events->record('whatsapp_event_ignored', 'whatsapp', status: 'ignored', errorCode: 'unsupported_payload');

            return;
        }

        foreach ($normalized as $item) {
            $event = WhatsAppWebhookEvent::query()->firstOrCreate(
                ['external_event_id' => $item['external_event_id']],
                ['event_type' => $item['type'], 'status' => 'processing'],
            );
            if (! $event->wasRecentlyCreated && in_array($event->status, ['processed', 'duplicate'], true)) {
                $this->events->record('duplicate_blocked', 'whatsapp', messageId: $item['external_event_id'], status: 'duplicate');

                continue;
            }

            if ($item['type'] === 'status') {
                $this->outbound->applyStatus($item['status']);
                $this->costs->record('meta', 'meta_status', 'meta-status:'.$item['external_event_id'], metrics: ['category' => $item['status']['category'] ?? null, 'billable' => $item['status']['billable'] ?? null]);
                $event->update(['status' => 'processed']);

                continue;
            }

            $message = $item['message'];
            $this->costs->record('meta', 'meta_inbound', 'meta-inbound:'.$message->externalMessageId);
            $inbound = WhatsAppInboundMessage::query()->firstOrCreate([
                'provider' => (string) ($message->metadata['provider'] ?? config('whatsapp.provider')),
                'instance' => (string) ($message->metadata['instance'] ?? config('whatsapp.phone_number_id', 'default')),
                'external_message_id' => $message->externalMessageId,
            ], [
                'external_user_id' => $message->externalUserId,
                'message_type' => $message->messageType,
                'original_timestamp' => $message->receivedAt,
                'received_at' => now(),
                'status' => 'queued',
                'metadata' => ['recovered' => (bool) ($message->metadata['recovered'] ?? false), 'reply_to' => $message->replyToMessageId],
            ]);
            if (! $inbound->wasRecentlyCreated && in_array($inbound->status, ['processed', 'awaiting_confirmation', 'review_required'], true)) {
                $event->update(['status' => 'duplicate']);

                continue;
            }
            if ($inbound->wasRecentlyCreated) {
                foreach ($message->attachments as $attachment) {
                    AgentAttachment::query()->create(['source' => 'whatsapp', 'external_id' => $attachment['provider_media_id'] ?? null, 'mime_type' => $attachment['mime_type'] ?? null, 'whatsapp_inbound_message_id' => $inbound->id, 'metadata' => array_diff_key($attachment, array_flip(['url']))]);
                }
            }
            try {
                $inbound->update(['status' => 'processing', 'attempts' => max($inbound->attempts + 1, $attempt), 'next_retry_at' => null]);
                $event->update(['status' => 'processing', 'error_code' => null]);
                $response = $this->agent->handle($message);
                $conversationMessage = AgentConversationMessage::query()->where('external_message_id', $message->externalMessageId)->first();
                $inboundStatus = $response->responseType === 'confirmation' ? 'awaiting_confirmation' : ($response->success ? 'processed' : 'review_required');
                $inbound->update(['agent_conversation_message_id' => $conversationMessage?->id, 'error_code' => $response->errorCode]);
                $this->connection->current()->update(['last_received_at' => now()]);
                if ($message->metadata['recovered'] ?? false) {
                    $this->events->record('whatsapp_message_recovered', 'whatsapp', messageId: $message->externalMessageId, status: $inboundStatus);
                }
                $this->events->record('whatsapp_message_processed', 'whatsapp', messageId: $message->externalMessageId, status: $response->success ? 'success' : 'controlled_error', errorCode: $response->errorCode);
                $sent = $this->outbound->sendText($message->externalUserId, $this->formatter->text($response), $event);
                if ($sent->status === 'sent') {
                    $this->connection->current()->update(['last_sent_at' => now()]);
                }
                $inbound->update(['status' => $inboundStatus, 'processed_at' => now(), 'next_retry_at' => null]);
                $event->update(['status' => 'processed', 'error_code' => null]);
            } catch (Throwable $exception) {
                $code = class_basename($exception);
                $transient = $this->failures->isTransient($exception);
                $willRetry = $transient && ! $finalAttempt;
                $status = $willRetry ? 'retrying' : 'review_required';
                $inbound->update([
                    'status' => $status,
                    'error_code' => $code,
                    'last_failed_at' => now(),
                    'next_retry_at' => $willRetry ? now()->addSeconds($retryDelay) : null,
                    'processed_at' => $willRetry ? null : now(),
                ]);
                $event->update(['status' => $willRetry ? 'retrying' : 'failed', 'error_code' => $code]);
                if (! $willRetry) {
                    $event->outboundMessages()->whereNotIn('status', ['sent', 'delivered', 'read'])->update(['status' => 'failed']);
                }
                $this->events->record($willRetry ? 'whatsapp_processing_retrying' : 'whatsapp_processing_failed', 'whatsapp', messageId: $message->externalMessageId, status: $status, errorCode: $code, metadata: ['attempt' => $inbound->attempts, 'next_retry_in_seconds' => $willRetry ? $retryDelay : null]);
                if ($transient) {
                    throw $exception;
                }
            }
        }
    }
}
