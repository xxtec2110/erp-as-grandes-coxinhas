<?php

return [
    'enabled' => (bool) env('PDV_ENABLED', false),
    'provider' => env('PDV_PROVIDER', 'grandchef'),
    'sync_enabled' => (bool) env('PDV_SYNC_ENABLED', false),
    'sync_interval_minutes' => env('PDV_SYNC_INTERVAL_MINUTES'),
    'webhook_enabled' => (bool) env('PDV_WEBHOOK_ENABLED', false),
    'payload_max_bytes' => (int) env('PDV_PAYLOAD_MAX_BYTES', 262144),
];
