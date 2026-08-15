<?php

namespace App\Services;

use App\Models\UserExternalIdentity;

readonly class WhatsAppIdentityResolution
{
    public function __construct(public string $status, public ?UserExternalIdentity $identity = null, public ?string $normalized = null) {}

    public function authorized(): bool
    {
        return $this->status === 'authorized';
    }
}
