<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentResponse;
use App\Models\ProductionSubmission;
use App\Models\ProductionUserPolicy;
use App\Models\UserExternalIdentity;

class RestrictedProductionInteractionService
{
    public function __construct(private ProductionSubmissionService $submissions, private AgentEventService $events) {}

    public function guard(AgentMessage $message, UserExternalIdentity $identity): ?ErpAgentResponse
    {
        $policy = ProductionUserPolicy::query()->where('user_id', $identity->user_id)->where('active', true)->where('restricted', true)->first();
        if (! $policy) {
            return null;
        }if ($message->messageType === 'image') {
            return null;
        }$text = mb_strtoupper(trim($message->text ?? ''));
        $submission = ProductionSubmission::query()->where('production_user_policy_id', $policy->id)->where('status', 'awaiting_confirmation')->latest()->first();
        if ($submission && in_array($text, ['1', 'SIM', 'OK', 'CONFIRMAR'], true)) {
            $this->submissions->confirm($submission, $identity->user);

            return new ErpAgentResponse(true, 'Produção confirmada e registrada.');
        }if ($submission && in_array($text, ['2', 'NÃO', 'NAO'], true)) {
            $this->submissions->reject($submission, $identity->user);

            return new ErpAgentResponse(true, 'Foto rejeitada. Envie uma nova foto completa.');
        }$this->events->record('restricted_production_message_blocked', $message->channel, $identity->user, messageId: $message->externalMessageId, status: 'denied', errorCode: 'restricted_production_profile', metadata: ['message_type' => $message->messageType]);

        return ErpAgentResponse::error('Envie somente a foto do quadro de produção ou responda SIM/OK/1 ou NÃO/2 à prévia.','restricted_production_profile','unauthorized');
    }
}
