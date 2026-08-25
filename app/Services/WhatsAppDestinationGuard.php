<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Models\WhatsAppConnection;
use DomainException;

class WhatsAppDestinationGuard
{
    public function __construct(private PhoneNumberNormalizer $phones) {}

    public function rejectionReason(AgentMessage $message): ?string
    {
        $expectedPhoneNumberId = trim((string) config('whatsapp.phone_number_id'));
        $receivedPhoneNumberId = trim((string) ($message->metadata['phone_number_id'] ?? ''));
        if ($expectedPhoneNumberId !== '' && ! hash_equals($expectedPhoneNumberId, $receivedPhoneNumberId)) {
            return 'wrong_business_destination';
        }

        $businessPhone = WhatsAppConnection::query()
            ->where('provider', (string) config('whatsapp.provider', 'meta'))
            ->whereNotNull('business_phone_normalized')
            ->latest('id')
            ->value('business_phone_normalized');
        if (! is_string($businessPhone) || $businessPhone === '') {
            return null;
        }

        try {
            if (hash_equals($businessPhone, $this->phones->normalize($message->externalUserId))) {
                return 'business_number_self_message';
            }
            $receivedBusinessPhone = $message->metadata['business_phone_number'] ?? null;
            if (is_string($receivedBusinessPhone) && $receivedBusinessPhone !== ''
                && ! hash_equals($businessPhone, $this->phones->normalize($receivedBusinessPhone))) {
                return 'wrong_business_destination';
            }
        } catch (DomainException) {
            return 'invalid_business_destination';
        }

        return null;
    }
}
