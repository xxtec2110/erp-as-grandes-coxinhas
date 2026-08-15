<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Agent\AiInterpretation;
use App\Agent\ErpAgentResponse;
use App\Models\AgentAttachment;
use App\Models\ProductionSubmission;
use App\Models\ProductionUserPolicy;
use App\Models\UserExternalIdentity;
use App\Production\ProductionBoardInterpretation;
use App\Production\ProductionBoardValidationException;
use DomainException;

class RestrictedProductionInteractionService
{
    public function __construct(
        private ProductionSubmissionService $submissions,
        private ProductionBoardValidationService $validator,
        private AgentEventService $events,
    ) {}

    public function guard(AgentMessage $message, UserExternalIdentity $identity): ?ErpAgentResponse
    {
        $policy = $this->policy($identity);
        if (! $policy) {
            return null;
        }
        $submission = ProductionSubmission::query()
            ->where('production_user_policy_id', $policy->id)
            ->latest()
            ->first();
        if ($message->messageType === 'image') {
            if ($submission?->status !== 'awaiting_confirmation') {
                return null;
            }

            $reference = count($message->attachments) === 1 ? $message->attachments[0] : null;
            $attachmentId = is_array($reference) ? ($reference['id'] ?? null) : $reference;
            $attachment = filter_var($attachmentId, FILTER_VALIDATE_INT) !== false
                ? AgentAttachment::query()->where('created_by', $identity->user_id)->find((int) $attachmentId)
                : null;
            if ($attachment?->id === $submission->agent_attachment_id) {
                return new ErpAgentResponse(
                    true,
                    'A prévia desta foto já está aguardando resposta. Responda 1/SIM/OK para confirmar ou 2/NÃO para descartar.',
                    'confirmation',
                    ['submission_id' => $submission->id],
                    [['id' => '1', 'label' => 'SIM / OK'], ['id' => '2', 'label' => 'NÃO']],
                    ['id' => $submission->pending_agent_action_id],
                );
            }
            if ($attachment) {
                $this->submissions->discardInvalid($attachment, $identity->user, 'preview_already_pending');
            }

            return ErpAgentResponse::error(
                'Já existe uma prévia aguardando resposta. Responda 1/SIM/OK para confirmar ou 2/NÃO para descartar antes de enviar outra foto.',
                'production_board_confirmation_pending',
            );
        }

        $text = mb_strtoupper(trim($message->text ?? ''));
        try {
            if ($submission?->status === 'awaiting_confirmation' && in_array($text, ['1', 'SIM', 'OK', 'CONFIRMAR'], true)) {
                $this->submissions->confirm($submission, $identity->user);

                return new ErpAgentResponse(true, 'Produção confirmada e registrada.');
            }
            if ($submission?->status === 'confirmed' && in_array($text, ['1', 'SIM', 'OK', 'CONFIRMAR'], true)) {
                return new ErpAgentResponse(true, 'A produção já estava confirmada e permanece registrada uma única vez.');
            }
            if ($submission?->status === 'awaiting_confirmation' && in_array($text, ['2', 'NÃO', 'NAO'], true)) {
                $this->submissions->reject($submission, $identity->user);

                return new ErpAgentResponse(true, 'Foto rejeitada. Envie uma nova foto completa.');
            }
        } catch (DomainException $exception) {
            return ErpAgentResponse::error($exception->getMessage(), 'restricted_production_validation');
        }

        $this->events->record('restricted_production_message_blocked', $message->channel, $identity->user, messageId: $message->externalMessageId, status: 'denied', errorCode: 'restricted_production_profile', metadata: ['message_type' => $message->messageType]);

        return ErpAgentResponse::error('Envie somente a foto do quadro de produção ou responda SIM/OK/1 ou NÃO/2 à prévia.', 'restricted_production_profile', 'unauthorized');
    }

    public function handleInterpretedImage(
        AgentMessage $message,
        UserExternalIdentity $identity,
        AiInterpretation $interpretation,
        ?int $conversationId = null,
    ): ?ErpAgentResponse {
        $policy = $this->policy($identity);
        if (! $policy || $message->messageType !== 'image') {
            return null;
        }

        $reference = count($message->attachments) === 1 ? $message->attachments[0] : null;
        $attachmentId = is_array($reference) ? ($reference['id'] ?? null) : $reference;
        $attachment = filter_var($attachmentId, FILTER_VALIDATE_INT) !== false
            ? AgentAttachment::query()->find((int) $attachmentId)
            : null;
        if ($attachment === null) {
            return ErpAgentResponse::error(ProductionBoardValidationService::INVALID_PRODUCT_MESSAGE, 'production_board_invalid');
        }

        if ($interpretation->tool !== 'production.orders.complete_batch' || $interpretation->sourceType !== 'image') {
            $this->submissions->discardInvalid($attachment, $identity->user, 'invalid_board_intent');

            return ErpAgentResponse::error(ProductionBoardValidationService::INVALID_PRODUCT_MESSAGE, 'production_board_invalid');
        }

        try {
            $board = $this->validator->validate(
                ProductionBoardInterpretation::fromAi($interpretation),
                $policy,
                $attachment,
                $identity,
            );
            $submission = $this->submissions->preview($policy, $attachment, $board, $identity->user, conversationId: $conversationId);
        } catch (ProductionBoardValidationException $exception) {
            $this->submissions->discardInvalid($attachment, $identity->user, $exception->reason);

            return ErpAgentResponse::error($exception->getMessage(), 'production_board_invalid');
        } catch (DomainException $exception) {
            $this->submissions->discardInvalid($attachment, $identity->user, 'preview_not_available');

            return ErpAgentResponse::error($exception->getMessage(), 'restricted_production_validation');
        }

        $this->events->record('production_board_preview_created', $message->channel, $identity->user, $conversationId, $message->externalMessageId, 'production.orders.complete_batch', [
            'submission_id' => $submission->id,
            'location_id' => $policy->location_id,
            'operation_date' => $board->operationDate?->toDateString(),
            'item_count' => count($board->items),
            'total' => $board->total(),
        ]);

        return new ErpAgentResponse(
            true,
            $this->previewMessage($policy, $board),
            'confirmation',
            ['submission_id' => $submission->id, 'location_id' => $policy->location_id, 'total' => $board->total()],
            [['id' => '1', 'label' => 'SIM / OK'], ['id' => '2', 'label' => 'NÃO']],
            ['id' => $submission->pending_agent_action_id],
        );
    }

    private function policy(UserExternalIdentity $identity): ?ProductionUserPolicy
    {
        return ProductionUserPolicy::query()
            ->with('location')
            ->where('user_id', $identity->user_id)
            ->where('active', true)
            ->where('restricted', true)
            ->first();
    }

    private function previewMessage(ProductionUserPolicy $policy, ProductionBoardInterpretation $board): string
    {
        $lines = [
            '📸 PRODUÇÃO IDENTIFICADA',
            '',
            '📅 Data: '.$board->operationDate?->format('d/m/Y'),
            '🏪 Unidade: '.mb_strtoupper($policy->location->name),
            '',
        ];
        foreach ($board->items as $item) {
            $lines[] = $item['product_name'].': '.$item['quantity'];
        }
        $lines[] = '';
        $lines[] = 'TOTAL: '.$board->total();
        $lines[] = '';
        $lines[] = 'Está tudo correto?';
        $lines[] = '';
        $lines[] = '1️⃣ SIM / OK';
        $lines[] = '2️⃣ NÃO';

        return implode("\n", $lines);
    }
}
