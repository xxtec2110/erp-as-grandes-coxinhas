<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Services\AgentEventService;
use App\WhatsApp\WhatsAppSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode', $request->query('hub_mode'));
        $token = (string) $request->query('hub.verify_token', $request->query('hub_verify_token', ''));
        $challenge = (string) $request->query('hub.challenge', $request->query('hub_challenge', ''));
        $expected = (string) config('whatsapp.verify_token');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppSignatureValidator $validator, AgentEventService $events): Response
    {
        if (! config('whatsapp.enabled')) {
            return response('Channel disabled', 503);
        }
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('whatsapp.webhook_max_bytes', 1048576)) {
            $events->record('whatsapp_event_rejected', 'whatsapp', status: 'rejected', errorCode: 'payload_too_large');

            return response('Payload too large', 413);
        }
        if (! $validator->valid($raw, $request->header('X-Hub-Signature-256'))) {
            $events->record('whatsapp_signature_rejected', 'whatsapp', status: 'rejected', errorCode: 'invalid_signature');

            return response('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $events->record('whatsapp_event_rejected', 'whatsapp', status: 'rejected', errorCode: 'invalid_json');

            return response('Invalid payload', 422);
        }

        ProcessWhatsAppWebhook::dispatch($payload);

        return response('EVENT_RECEIVED', 200);
    }
}
