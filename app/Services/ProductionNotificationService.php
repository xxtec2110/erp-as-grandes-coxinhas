<?php

namespace App\Services;

use App\Models\ProductionSubmission;
use App\Models\ProductionUserPolicy;
use App\Models\User;
use App\WhatsApp\WhatsAppClientInterface;
use Carbon\CarbonImmutable;

class ProductionNotificationService
{
    public function __construct(private WhatsAppClientInterface $client, private DailyProductionBriefService $briefs, private AgentEventService $events) {}

    public function sendBrief(ProductionUserPolicy $policy, CarbonImmutable $date): ProductionSubmission
    {
        $submission = ProductionSubmission::query()->firstOrCreate(['production_user_policy_id' => $policy->id, 'operation_date' => $date->toDateString()], ['status' => 'awaiting_photo', 'idempotency_key' => "production:{$policy->id}:{$date->toDateString()}"]);
        if ($submission->briefing_sent) {
            return $submission;
        }$recipient = $policy->user->externalIdentities()->where('channel', 'whatsapp')->where('active', true)->value('external_user_id');
        if (! $recipient) {
            return $submission;
        }$this->client->sendText($recipient, $this->briefs->build($policy, $date));
        $submission->update(['briefing_sent' => true, 'briefing_sent_at' => now()]);
        $this->events->record('production_brief_sent', 'whatsapp', $policy->user, metadata: ['location_id' => $policy->location_id, 'operation_date' => $date->toDateString()]);

        return $submission->refresh();
    }

    public function sendMissingAlert(ProductionUserPolicy $policy, CarbonImmutable $date): ProductionSubmission
    {
        $submission = ProductionSubmission::query()->firstOrCreate(['production_user_policy_id' => $policy->id, 'operation_date' => $date->toDateString()], ['status' => 'not_submitted', 'idempotency_key' => "production:{$policy->id}:{$date->toDateString()}"]);
        if ($submission->alert_sent || $submission->status === 'confirmed') {
            return $submission;
        }$admin = User::query()->where('is_super_admin', true)->first();
        $recipient = $admin?->externalIdentities()->where('channel', 'whatsapp')->where('active', true)->value('external_user_id');
        if (! $recipient) {
            return $submission;
        }$text = "⚠️ PRODUÇÃO NÃO REGISTRADA\n\nFuncionário: {$policy->user->name}\nUnidade: {$policy->location->name}\nData: {$date->format('d/m/Y')}\n\nA produção do dia ainda não foi confirmada.\nPrazo final: ".substr($policy->cutoff_time, 0, 5).'.';
        $this->client->sendText($recipient, $text);
        $submission->update(['alert_sent' => true, 'alert_sent_at' => now()]);
        $this->events->record('production_missing_alert_sent', 'whatsapp', $admin, metadata: ['policy_id' => $policy->id, 'operation_date' => $date->toDateString()]);

        return $submission->refresh();
    }
}
