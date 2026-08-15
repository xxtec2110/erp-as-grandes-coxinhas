<?php

namespace App\Services;

use App\Models\UserExternalIdentity;
use DomainException;

class WhatsAppIdentityResolver
{
    public function __construct(private PhoneNumberNormalizer $phones) {}

    public function resolve(string $externalIdentifier): WhatsAppIdentityResolution
    {
        $exact = UserExternalIdentity::query()->with(['user.roles', 'user.permissions', 'user.locations'])
            ->where('channel', 'whatsapp')->where('external_user_id', $externalIdentifier)->first();
        try {
            $normalized = $this->phones->normalize($externalIdentifier);
        } catch (DomainException) {
            return $exact === null ? new WhatsAppIdentityResolution('invalid_identifier') : $this->classify($exact);
        }

        $identity = $exact ?? UserExternalIdentity::query()
            ->with(['user.roles', 'user.permissions', 'user.locations'])
            ->where('channel', 'whatsapp')
            ->where(function ($query) use ($externalIdentifier, $normalized): void {
                $query->where('phone_normalized', $normalized)
                    ->orWhere('external_user_id', $externalIdentifier)
                    ->orWhere('external_user_id', ltrim($normalized, '+'));
            })->first();

        if ($identity === null) {
            return new WhatsAppIdentityResolution('unknown_identity', normalized: $normalized);
        }

        return $this->classify($identity, $normalized);
    }

    private function classify(UserExternalIdentity $identity, ?string $normalized = null): WhatsAppIdentityResolution
    {
        if (! $identity->active || $identity->status !== 'approved') {
            return new WhatsAppIdentityResolution('inactive_identity', $identity, $normalized);
        }
        if ($identity->user === null || ! $identity->user->active) {
            return new WhatsAppIdentityResolution('inactive_user', $identity, $normalized);
        }

        return new WhatsAppIdentityResolution('authorized', $identity, $normalized);
    }
}
