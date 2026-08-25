<?php

namespace App\Services;

use App\Agent\AgentToolRegistry;
use App\Models\AgentEvent;
use App\Models\AgentUsageCost;
use App\Models\PendingAgentAction;
use App\Models\UserExternalIdentity;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Str;

class AgentChannelHealthService
{
    public function __construct(
        private AgentToolRegistry $tools,
        private AgentCostService $costs,
        private PhoneNumberNormalizer $phones,
    ) {}

    public function summary(WhatsAppConnection $connection): array
    {
        $model = (string) config('ai.models.text');
        $openAiConfigured = (bool) config('ai.openai.enabled')
            && filled(config('ai.openai.api_key'))
            && $model !== ''
            && $this->costs->openAiPricingReady($model);
        $appUrl = (string) config('app.url');
        $publicWebhookReady = str_starts_with(mb_strtolower($appUrl), 'https://');
        $metaAssets = [
            'app' => filled(config('whatsapp.app_id')),
            'waba' => filled(config('whatsapp.business_account_id')),
            'phone' => filled(config('whatsapp.phone_number_id')),
            'token' => filled(config('whatsapp.access_token')),
            'app_secret' => filled(config('whatsapp.app_secret')),
            'verify_token' => filled(config('whatsapp.verify_token')),
            'embedded_signup' => filled(config('whatsapp.embedded_signup_config_id')),
        ];

        return [
            'agent' => [
                'status' => 'READY',
                'identity_gate' => true,
                'authorized_identities' => UserExternalIdentity::query()->where('channel', 'whatsapp')->where('active', true)->where('respond_enabled', true)->count(),
                'pending_actions' => PendingAgentAction::query()->where('status', 'pending')->count(),
                'tools' => count($this->tools->all()),
                'errors' => AgentEvent::query()->whereIn('event_type', ['internal_error', 'tool_failed', 'whatsapp_processing_failed'])->where('created_at', '>=', now()->subDay())->count(),
            ],
            'openai' => [
                'status' => $openAiConfigured ? 'READY' : 'NOT CONFIGURED',
                'active_provider' => mb_strtoupper((string) config('ai.provider', 'disabled')),
                'key_configured' => filled(config('ai.openai.api_key')),
                'model' => $model !== '' ? $model : 'Não configurado',
                'responses_api' => true,
                'transcription' => filled(config('ai.models.audio')),
                'vision' => filled(config('ai.models.vision')),
                'document' => filled(config('ai.models.document')),
                'live_test' => (bool) config('ai.live_test.enabled'),
                'usage_count' => AgentUsageCost::query()->where('provider', 'openai')->where('created_at', '>=', now()->startOfMonth())->count(),
                'cost_brl' => $this->costs->monthlyOpenAiSpendBrl(),
            ],
            'whatsapp' => [
                'status' => $this->whatsAppStatus($connection, $metaAssets, $publicWebhookReady),
                'provider' => mb_strtoupper((string) config('whatsapp.provider', 'meta')).' CLOUD API',
                'enabled' => (bool) config('whatsapp.enabled'),
                'business_phone' => $this->phones->mask($connection->business_phone_normalized),
                'meta_assets' => $metaAssets,
                'token_type' => mb_strtoupper((string) config('whatsapp.access_token_type', 'unknown')),
                'public_webhook' => $publicWebhookReady,
                'webhook_url' => rtrim($appUrl, '/').'/api/webhooks/whatsapp',
                'last_inbound' => $connection->last_received_at,
                'last_outbound' => $connection->last_sent_at,
                'last_check' => $connection->last_checked_at,
                'last_error' => $this->safeReason($connection->reason),
                'can_check_meta' => (bool) config('whatsapp.enabled') && $metaAssets['phone'] && $metaAssets['token'],
            ],
            'coexistence' => [
                'status' => mb_strtoupper($connection->coexistence_status ?: 'inconclusive'),
                'code_support' => false,
                'official_qr' => 'DEPENDE DA META',
                'embedded_signup_status' => mb_strtoupper($connection->embedded_signup_status ?: 'not_configured'),
            ],
        ];
    }

    private function whatsAppStatus(WhatsAppConnection $connection, array $assets, bool $publicWebhookReady): string
    {
        if (! $connection->business_phone_normalized || ! config('whatsapp.enabled')) {
            return 'NOT CONFIGURED';
        }
        if (! $assets['phone'] || ! $assets['waba']) {
            return 'PHONE NOT PROVISIONED';
        }
        if (! $assets['token'] || ! $assets['app_secret'] || ! $assets['verify_token']) {
            return 'NOT CONFIGURED';
        }
        if (! $publicWebhookReady) {
            return 'PUBLIC WEBHOOK REQUIRED';
        }

        return $connection->status === 'operational' ? 'READY' : 'ERROR';
    }

    private function safeReason(?string $reason): ?string
    {
        if (! is_string($reason) || $reason === '') {
            return null;
        }

        return Str::limit(preg_replace('/[A-Za-z0-9._-]{24,}/', '[protegido]', $reason) ?? 'Erro protegido', 120);
    }
}
