<?php

namespace App\WhatsApp;

use App\Agent\AgentMessage;
use DateTimeImmutable;

class WhatsAppMessageNormalizer
{
    /** @return array<int, array{type: string, external_event_id: string, message?: AgentMessage, status?: array<string, mixed>}> */
    public function normalize(array $payload): array
    {
        $events = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');
                foreach ($value['messages'] ?? [] as $message) {
                    if (! isset($message['id'], $message['from'], $message['type'])) {
                        continue;
                    }
                    $type = (string) $message['type'];
                    $contact = $contacts->get($message['from'], []);
                    $events[] = ['type' => 'message', 'external_event_id' => $message['id'], 'message' => new AgentMessage(
                        'whatsapp', (string) $message['from'], (string) $message['id'], $this->text($message, $type), $type,
                        $this->attachments($message, $type), $message['context']['id'] ?? null,
                        array_filter(['display_name' => $contact['profile']['name'] ?? null, 'phone_number_id' => $phoneNumberId, 'provider' => 'meta', 'instance' => $phoneNumberId, 'context_from' => $message['context']['from'] ?? null], fn ($item) => $item !== null),
                        isset($message['timestamp']) ? new DateTimeImmutable('@'.(int) $message['timestamp']) : null,
                    )];
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (! isset($status['id'], $status['status'])) {
                        continue;
                    }
                    $timestamp = (string) ($status['timestamp'] ?? '');
                    $events[] = ['type' => 'status', 'external_event_id' => implode(':', [$status['id'], $status['status'], $timestamp]), 'status' => ['provider_message_id' => (string) $status['id'], 'status' => (string) $status['status'], 'recipient' => $status['recipient_id'] ?? null, 'category' => data_get($status, 'pricing.category'), 'billable' => data_get($status, 'pricing.billable'), 'error_code' => $status['errors'][0]['code'] ?? null]];
                }
            }
        }

        return $events;
    }

    private function text(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null, 'interactive' => $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null, default => null
        };
    }

    private function attachments(array $message, string $type): array
    {
        if (! in_array($type, ['audio', 'image', 'document'], true) || ! isset($message[$type]['id'])) {
            return [];
        }

        return [array_filter(['provider_media_id' => $message[$type]['id'], 'type' => $type, 'mime_type' => $message[$type]['mime_type'] ?? null, 'filename' => $message[$type]['filename'] ?? null, 'caption' => $message[$type]['caption'] ?? null], fn ($item) => $item !== null)];
    }
}
