<?php

namespace App\WhatsApp;

use RuntimeException;

class DisabledWhatsAppClient implements WhatsAppClientInterface
{
    public function sendText(string $recipient, string $text): string
    {
        throw new RuntimeException('whatsapp_client_disabled');
    }

    public function channelStatus(): array
    {
        return ['status' => 'unavailable', 'reason' => 'provider_disabled'];
    }
}
