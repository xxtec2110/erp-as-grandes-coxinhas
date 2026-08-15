<?php

namespace App\Services;

use App\Models\Location;
use App\Models\UserExternalIdentity;

class AgentAccessPolicy
{
    public function __construct(private AuthorizationService $authorization) {}

    public function canUse(UserExternalIdentity $identity, string $messageType): bool
    {
        if (! $identity->active || $identity->status !== 'approved' || ! $identity->user?->active) {
            return false;
        }
        [$flag, $permission] = match ($messageType) {
            'audio', 'transcribed_audio' => [$identity->voice_allowed, 'agent.audio.use'],
            'image' => [$identity->image_allowed, 'agent.image.use'],
            'document' => [$identity->document_allowed, 'agent.document.use'],
            default => [$identity->structured_commands_allowed, 'agent.text.use'],
        };

        return $flag && $this->authorization->allows($identity->user, $permission);
    }

    public function canRunTool(UserExternalIdentity $identity, string $permission, Location|int|null $location = null, bool $writes = false): bool
    {
        return $this->authorization->allows($identity->user, $permission, $location)
            && (! $writes || $this->authorization->allows($identity->user, 'agent.write.use'));
    }
}
