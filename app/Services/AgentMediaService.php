<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Agent\AudioTranscriptionProviderInterface;
use App\Models\AgentAttachment;
use App\Models\User;
use App\Models\WhatsAppInboundMessage;
use App\WhatsApp\WhatsAppMediaDownloaderInterface;
use DomainException;

class AgentMediaService
{
    public function __construct(
        private WhatsAppMediaDownloaderInterface $downloader,
        private AudioTranscriptionProviderInterface $transcriber,
        private AgentAttachmentService $attachments,
        private AuthorizationService $authorization,
        private AgentCostService $costs,
        private AgentEventService $events,
    ) {}

    public function prepare(AgentMessage $message, WhatsAppInboundMessage $inbound): AgentMessage
    {
        if (! in_array($message->messageType, ['image', 'document', 'audio'], true)) {
            return $message;
        }
        $identity = $inbound->identity()->with('user')->first();
        if ($identity === null || $identity->channel !== $message->channel || ! $identity->active || $identity->status !== 'approved' || $identity->user === null) {
            return $message;
        }
        $permission = match ($message->messageType) {
            'image' => 'agent.image.use', 'document' => 'agent.document.use', default => 'agent.audio.use',
        };
        $flag = match ($message->messageType) {
            'image' => $identity->image_allowed, 'document' => $identity->document_allowed, default => $identity->voice_allowed,
        };
        if (! $flag || ! $this->authorization->allows($identity->user, $permission)) {
            return $message;
        }
        if ($this->costs->summary()['saving_mode']) {
            throw new DomainException('media_blocked_by_saving_mode');
        }
        $locations = $this->authorization->accessibleLocations($identity->user);
        if ($locations->count() !== 1) {
            throw new DomainException('media_location_required');
        }
        $location = $locations->first();
        $stored = [];
        foreach ($message->attachments as $reference) {
            $mediaId = is_array($reference) ? ($reference['provider_media_id'] ?? null) : null;
            if (! is_string($mediaId) || $mediaId === '') {
                throw new DomainException('media_id_missing');
            }
            $attachment = AgentAttachment::query()->where('source', 'whatsapp')->where('external_id', $mediaId)->where('created_by', $identity->user->id)->whereNotNull('path')->first();
            if ($attachment === null) {
                $this->events->record('media_download_started', $message->channel, $identity->user, messageId: $message->externalMessageId, metadata: ['media_type' => $message->messageType]);
                $attachment = $this->attachments->storeDownloaded($this->downloader->download($mediaId), 'agent', $location->id, 'temporary', $identity->user, $inbound->id);
                $this->events->record('media_downloaded', $message->channel, $identity->user, messageId: $message->externalMessageId, metadata: ['attachment_id' => $attachment->id, 'media_type' => $message->messageType]);
            } else {
                $this->events->record('media_cache_hit', $message->channel, $identity->user, messageId: $message->externalMessageId, metadata: ['attachment_id' => $attachment->id]);
            }
            $stored[] = $attachment;
        }
        if ($message->messageType !== 'audio') {
            return $this->copy($message, $message->messageType, $message->text, collect($stored)->pluck('id')->all());
        }
        $attachment = $stored[0] ?? throw new DomainException('audio_attachment_missing');
        $transcription = $this->transcribeStored($attachment, $identity->user, $location->id, $message);
        if (trim($transcription) === '') {
            throw new DomainException('audio_transcription_empty');
        }

        return $this->copy($message, 'transcribed_audio', $transcription, [$attachment->id]);
    }

    public function transcribeStored(AgentAttachment $attachment, User $user, int $locationId, AgentMessage $message): string
    {
        $key = hash('sha256', implode('|', [$attachment->content_hash, config('ai.audio_provider'), config('ai.models.audio')]));
        $cached = data_get($attachment->metadata, 'audio_transcriptions.'.$key);
        if (is_array($cached) && is_string($cached['text'] ?? null)) {
            $this->events->record('audio_transcription_cache_hit', $message->channel, $user, messageId: $message->externalMessageId, metadata: ['attachment_id' => $attachment->id]);

            return $cached['text'];
        }
        $started = hrtime(true);
        $result = $this->transcriber->transcribe($attachment);
        $metadata = $attachment->metadata ?? [];
        $metadata['audio_transcriptions'][$key] = ['text' => $result->text, 'provider' => (string) config('ai.audio_provider'), 'model' => $result->usage['model'] ?? config('ai.models.audio'), 'duration' => $result->duration];
        $attachment->update(['metadata' => $metadata, 'processing_status' => 'transcribed']);
        $quantity = $result->duration !== null ? max(1, (int) ceil((float) $result->duration)) : 1;
        $this->costs->record((string) config('ai.audio_provider'), 'ai_audio', 'audio:'.$attachment->content_hash, $user, [...$result->usage, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'quantity' => $quantity, 'location_id' => $locationId, 'operation_type' => 'audio_transcription', 'operation_id' => (string) $attachment->id]);
        $this->events->record('audio_transcribed', $message->channel, $user, messageId: $message->externalMessageId, metadata: ['attachment_id' => $attachment->id, 'provider' => config('ai.audio_provider'), 'model' => $result->usage['model'] ?? config('ai.models.audio')]);

        return $result->text;
    }

    private function copy(AgentMessage $message, string $type, ?string $text, array $attachments): AgentMessage
    {
        return new AgentMessage($message->channel, $message->externalUserId, $message->externalMessageId, $text, $type, $attachments, $message->replyToMessageId, [...$message->metadata, 'original_message_type' => $message->messageType], $message->receivedAt);
    }
}
