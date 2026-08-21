<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvInboundEvent;
use App\Models\PdvIntegrationEvent;
use App\Models\User;

class PdvIntegrationEventService
{
    public function __construct(private PdvPayloadSanitizer $sanitizer) {}

    public function record(string $type, ?PdvConnection $connection = null, ?PdvInboundEvent $inbound = null, ?User $user = null, ?string $status = null, array $metadata = [], ?int $durationMs = null, ?int $lagSeconds = null): void
    {
        PdvIntegrationEvent::query()->create(['pdv_connection_id' => $connection?->id, 'pdv_inbound_event_id' => $inbound?->id, 'user_id' => $user?->id, 'event_type' => $type, 'status' => $status, 'duration_ms' => $durationMs, 'lag_seconds' => $lagSeconds, 'metadata' => $this->sanitizer->sanitize($metadata)]);
    }
}
