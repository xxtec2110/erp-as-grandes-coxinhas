<?php

namespace App\Services;

use App\Mail\WhatsAppConnectionStatusMail;
use App\Models\WhatsAppConnection;
use App\WhatsApp\WhatsAppClientInterface;
use Illuminate\Support\Facades\Mail;

class WhatsAppConnectionService
{
    public function __construct(private WhatsAppClientInterface $client, private AgentEventService $events) {}

    public function current(): WhatsAppConnection
    {
        return WhatsAppConnection::query()->firstOrCreate([
            'provider' => (string) config('whatsapp.provider', 'meta'),
            'instance' => (string) config('whatsapp.phone_number_id', 'not-configured'),
        ], ['status' => 'unavailable', 'reason' => 'not_checked']);
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
