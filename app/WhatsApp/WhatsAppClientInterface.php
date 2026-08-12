<?php

namespace App\WhatsApp;

interface WhatsAppClientInterface
{
    public function sendText(string $recipient, string $text): string;

    /** @return array{status: string, reason?: string} */
    public function channelStatus(): array;
}
