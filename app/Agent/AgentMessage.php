<?php

namespace App\Agent;

use DateTimeInterface;

readonly class AgentMessage
{
    public function __construct(public string $channel, public string $externalUserId, public string $externalMessageId, public ?string $text = null, public string $messageType = 'text', public array $attachments = [], public ?string $replyToMessageId = null, public array $metadata = [], public ?DateTimeInterface $receivedAt = null) {}
}
