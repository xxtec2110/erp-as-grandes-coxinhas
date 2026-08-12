<?php

namespace App\WhatsApp;

class WhatsAppSignatureValidator
{
    public function valid(string $payload, ?string $signature): bool
    {
        $secret = (string) config('whatsapp.app_secret');

        return $secret !== ''
            && is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }
}
