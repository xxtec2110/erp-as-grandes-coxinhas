<?php

namespace App\Services;

use App\Mail\WhatsAppConnectionStatusMail;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\WhatsApp\WhatsAppClientInterface;
use Illuminate\Support\Facades\Mail;

class WhatsAppConnectionService
{
    public function __construct(
        private WhatsAppClientInterface $client,
        private AgentEventService $events,
        private PhoneNumberNormalizer $phones,
    ) {}

    public function current(): WhatsAppConnection
    {
        $provider = (string) config('whatsapp.provider', 'meta');
        $instance = trim((string) config('whatsapp.phone_number_id'));
        if ($instance === '') {
            return WhatsAppConnection::query()->where('provider', $provider)->latest('id')->first()
                ?? WhatsAppConnection::query()->create(['provider' => $provider, 'instance' => 'not-configured', 'status' => 'unavailable', 'reason' => 'not_checked']);
        }

        $history = WhatsAppConnection::query()->where('provider', $provider)->whereNotNull('business_phone_normalized')->latest('id')->first();

        return WhatsAppConnection::query()->firstOrCreate([
            'provider' => $provider,
            'instance' => $instance,
        ], [
            'status' => 'unavailable', 'reason' => 'not_checked',
            'business_phone_normalized' => $history?->business_phone_normalized,
            'coexistence_status' => $history?->coexistence_status ?? 'inconclusive',
            'embedded_signup_status' => $history?->embedded_signup_status ?? 'not_configured',
        ]);
    }

    public function configureBusinessPhone(string $phone, User $actor): WhatsAppConnection
    {
        $connection = $this->current();
        $normalized = $this->phones->normalize($phone);
        $changed = $connection->business_phone_normalized !== $normalized;
        $connection->update(['business_phone_normalized' => $normalized]);
        $this->events->record('whatsapp_business_phone_configured', 'web', $actor, status: 'configured', metadata: [
            'connection_id' => $connection->id,
            'changed' => $changed,
        ]);

        return $connection->refresh();
    }

    public function check(): WhatsAppConnection
    {
        try {
            $result = $this->client->channelStatus();

            return $this->transition($result['status'], $result['reason'] ?? null);
        } catch (\Throwable $exception) {
            return $this->transition('error', class_basename($exception));
        }
    }

    public function transition(string $status, ?string $reason = null): WhatsAppConnection
    {
        $connection = $this->current();
        $previous = $connection->status;
        $values = ['status' => $status, 'reason' => $reason, 'last_checked_at' => now()];
        if ($status === 'operational') {
            $values += ['last_connected_at' => now(), 'qr_code' => null];
        }
        if (in_array($status, ['degraded', 'unavailable'], true) && $previous !== $status) {
            $values['last_disconnected_at'] = now();
        }
        $connection->update($values);
        if ($previous !== $status) {
            $this->events->record('whatsapp_connection_changed', 'whatsapp', status: $status, metadata: ['previous_status' => $previous, 'reason' => $reason]);
            $this->alertTransition($connection->refresh(), $previous);
        }

        return $connection->refresh();
    }

    private function alertTransition(WhatsAppConnection $connection, string $previous): void
    {
        $email = config('whatsapp.alert_email');
        if (! is_string($email) || $email === '') {
            return;
        }

        if ($connection->status === 'operational' && $previous !== 'operational') {
            Mail::to($email)->queue(new WhatsAppConnectionStatusMail('OPERACIONAL', $connection->reason));
            $connection->update(['reconnect_alerted_at' => now(), 'disconnect_alerted_at' => null]);
        } elseif ($connection->status === 'unavailable' && $connection->disconnect_alerted_at === null) {
            Mail::to($email)->queue(new WhatsAppConnectionStatusMail('INDISPONÍVEL', $connection->reason));
            $connection->update(['disconnect_alerted_at' => now(), 'reconnect_alerted_at' => null]);
        }
    }
}
