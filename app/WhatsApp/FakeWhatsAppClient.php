<?php

namespace App\WhatsApp;

use RuntimeException;

class FakeWhatsAppClient implements WhatsAppClientInterface
{
    /** @var array<int, array{recipient: string, text: string, provider_message_id: string}> */
    private array $sent = [];

    private int $failuresRemaining = 0;

    private string $connectionState = 'operational';

    public function sendText(string $recipient, string $text): string
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException('temporary_send_failure');
        }

        $id = 'fake-'.str_pad((string) (count($this->sent) + 1), 6, '0', STR_PAD_LEFT);
        $this->sent[] = ['recipient' => $recipient, 'text' => $text, 'provider_message_id' => $id];

        return $id;
    }

    public function failNext(int $attempts = 1): void
    {
        $this->failuresRemaining = $attempts;
    }

    /** @return array<int, array{recipient: string, text: string, provider_message_id: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function channelStatus(): array
    {
        return ['status' => $this->connectionState];
    }

    public function setConnectionState(string $status): void
    {
        $this->connectionState = $status;
    }
}
