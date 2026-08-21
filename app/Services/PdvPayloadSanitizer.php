<?php

namespace App\Services;

use Illuminate\Support\Str;

class PdvPayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'authorization',
        'bearer',
        'bearer_token',
        'device',
        'device_token',
        'access_token',
        'api_key',
        'token',
        'secret',
        'client_secret',
        'password',
        'cookie',
        'set_cookie',
    ];

    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->sensitive($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function sensitive(string $key): bool
    {
        $normalized = Str::of($key)->snake()->lower()->toString();

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || str_ends_with($normalized, '_token')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_password');
    }
}
