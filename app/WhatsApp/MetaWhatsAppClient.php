<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaWhatsAppClient implements WhatsAppClientInterface
{
    public function sendText(string $recipient, string $text): string
    {
        $response = $this->request()->post($this->phonePath('messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ])->throw()->json();
        $id = data_get($response, 'messages.0.id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('meta_missing_message_id');
        }

        return $id;
    }

    public function channelStatus(): array
    {
        try {
            $data = $this->request()->get($this->phonePath(''), ['fields' => 'id,display_phone_number,quality_rating'])->throw()->json();

            return ['status' => isset($data['id']) ? 'operational' : 'degraded'];
        } catch (\Throwable $exception) {
            return ['status' => 'unavailable', 'reason' => class_basename($exception)];
        }
    }

    private function request(): PendingRequest
    {
        $token = (string) config('whatsapp.access_token');
        $baseUrl = rtrim((string) config('whatsapp.graph_base_url'), '/');
        if ($token === '' || (string) config('whatsapp.phone_number_id') === '') {
            throw new RuntimeException('meta_not_configured');
        }

        return Http::baseUrl($baseUrl)->withToken($token)->acceptJson()->asJson()->timeout(15);
    }

    private function phonePath(string $suffix): string
    {
        return '/'.trim((string) config('whatsapp.api_version'), '/').'/'.rawurlencode((string) config('whatsapp.phone_number_id')).($suffix === '' ? '' : '/'.$suffix);
    }
}
