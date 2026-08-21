<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvInboundEvent;
use Illuminate\Support\Str;

class PdvInboundService
{
    public function __construct(private PdvPayloadSanitizer $sanitizer) {}

    public function receive(PdvConnection $connection, string $eventId, string $type, array $payload, ?string $saleId = null): PdvInboundEvent
    {
        $sanitized = $this->sanitizer->sanitize($payload);
        $json = json_encode($sanitized, JSON_THROW_ON_ERROR);
        if (strlen($json) > config('pdv.payload_max_bytes')) {
            $sanitized = ['truncated' => true, 'keys' => array_keys($sanitized)];
            $json = json_encode($sanitized, JSON_THROW_ON_ERROR);
        }

        return PdvInboundEvent::query()->firstOrCreate(
            ['pdv_connection_id' => $connection->id, 'external_event_id' => $eventId],
            ['provider' => $connection->provider, 'external_sale_id' => $saleId, 'event_type' => $type, 'payload_hash' => hash('sha256', $json), 'payload' => $sanitized, 'received_at' => now(), 'status' => 'received'],
        );
    }

    public function syntheticEventId(string $provider, string $saleId, string $type, string $updatedAt): string
    {
        return Str::lower($provider).':'.hash('sha256', implode('|', [$saleId, $type, $updatedAt]));
    }
}
