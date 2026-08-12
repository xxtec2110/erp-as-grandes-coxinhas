<?php

namespace App\Agent;

use App\Models\AgentConversation;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentCostService;
use App\Services\AgentEventService;
use App\Services\AuthorizationService;
use App\Services\UndoLastOperationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class ErpAgentService
{
    public function __construct(private AgentConversationService $conversations, private PendingAgentActionService $pending, private DeterministicCommandParser $parser, private AiProviderInterface $ai, private AgentToolRegistry $registry, private AgentToolExecutor $executor, private AuthorizationService $authorization, private AgentResponseTemplate $templates, private AgentEventService $events, private UndoLastOperationService $undo, private AgentCostService $costs) {}

    public function handle(AgentMessage $message): ErpAgentResponse
    {
        $identity = UserExternalIdentity::query()->with('user')->where('channel', $message->channel)->where('external_user_id', $message->externalUserId)->first();
        if ($identity === null) {
            $identity = UserExternalIdentity::query()->create(['channel' => $message->channel, 'external_user_id' => $message->externalUserId, 'display_name' => $message->metadata['display_name'] ?? null, 'status' => 'pending', 'active' => false, 'metadata' => ['first_message_type' => $message->messageType], 'last_contact_at' => now()]);
            $this->events->record('unauthorized_identity', $message->channel, messageId: $message->externalMessageId, identityId: $identity->id, status: 'pending', errorCode: 'unknown_identity');

            return ErpAgentResponse::error('Usuário não identificado. Solicite autorização ao administrador.', 'unknown_identity', 'unauthorized');
        }
        if (! $identity->active || $identity->status !== 'approved' || $identity->user === null) {
            $identity->update(['last_contact_at' => now()]);
            $this->events->record('unauthorized_identity', $message->channel, messageId: $message->externalMessageId, identityId: $identity->id, status: $identity->status, errorCode: 'identity_not_approved');

            return ErpAgentResponse::error('Seu acesso ainda não está aprovado ou está bloqueado.', 'identity_not_approved', 'unauthorized');
        }
        $requiredPermission = match ($message->messageType) {
            'audio' => 'agent.audio.use', 'image' => 'agent.image.use', 'document' => 'agent.document.use', default => 'agent.text.use'
        };
        $identityFlag = match ($message->messageType) {
            'audio' => $identity->voice_allowed, 'image' => $identity->image_allowed, 'document' => $identity->document_allowed, default => $identity->structured_commands_allowed
        };
        $permissionAllowed = $this->authorization->allows($identity->user, $requiredPermission);
        if (! $identityFlag || ! $permissionAllowed) {
            $this->events->record('channel_permission_denied', $message->channel, $identity->user, messageId: $message->externalMessageId, identityId: $identity->id, status: 'denied', errorCode: 'channel_not_allowed');

            return ErpAgentResponse::error('Este tipo de mensagem não está liberado para o seu usuário.', 'channel_not_allowed', 'unauthorized');
        }
        $identity->update(['last_contact_at' => now()]);
        $user = $identity->user;
        $conversation = $this->conversations->conversation($user, $message->channel, $message->externalUserId);
        $existing = $conversation->messages()->where('external_message_id', $message->externalMessageId)->first();
        if (isset($existing?->structured_payload['response'])) {
            $this->events->record('duplicate_blocked', $message->channel, $user, $conversation->id, $message->externalMessageId);

            return ErpAgentResponse::fromArray($existing->structured_payload['response']);
        }
        $stored = $this->conversations->message($conversation, 'user', $message->text ?? '', ['message_type' => $message->messageType], $message->externalMessageId);
        $this->events->record('message_received', $message->channel, $user, $conversation->id, $message->externalMessageId);
        try {
            $response = $this->dispatch($message, $user, $conversation);
        } catch (AuthorizationException) {
            $this->events->record('action_denied', $message->channel, $user, $conversation->id, $message->externalMessageId);
            $response = ErpAgentResponse::error('Você não possui autorização para essa operação ou unidade.', 'forbidden', 'unauthorized');
        } catch (DomainException $exception) {
            $response = ErpAgentResponse::error($exception->getMessage(), 'validation_error');
        } catch (Throwable) {
            $this->events->record('internal_error', $message->channel, $user, $conversation->id, $message->externalMessageId);
            $response = ErpAgentResponse::error('Não foi possível concluir a solicitação. Tente novamente.', 'internal_error');
        }
        $stored->update(['structured_payload' => [...($stored->structured_payload ?? []), 'response' => $response->toArray()]]);
        $this->conversations->message($conversation, 'assistant', $response->message, $response->toArray());

        return $response;
    }

    private function dispatch(AgentMessage $message, User $user, AgentConversation $conversation): ErpAgentResponse
    {
        if (! in_array($message->messageType, ['text', 'interactive'], true)) {
            return ErpAgentResponse::error(
                'Recebi a mídia, mas áudio, imagem e documento ainda não podem ser processados. Envie a informação em texto.',
                'media_processing_unavailable',
            );
        }

        $text = trim($message->text ?? '');
        $active = $conversation->pendingActions()->where('status', 'pending')->latest()->first();
        if ($active !== null) {
            return $this->continuePending($active, $text, $user, $message);
        }
        if (in_array(mb_strtoupper($text), ['OI', 'OLÁ', 'OLA', 'MENU', 'AJUDA'], true)) {
            return $this->menu($user);
        }
        $intent = $this->parser->parse($text);
        if ($intent !== null) {
            $this->events->record('deterministic_command', $message->channel, $user, $conversation->id, $message->externalMessageId, $intent['tool']);
        }
        if ($intent === null) {
            $channelIdentity = UserExternalIdentity::query()->where('channel', $message->channel)->where('external_user_id', $message->externalUserId)->first();
            $testIntent = app()->environment('testing') && isset($message->metadata['fake_intent']);
            if (! $testIntent && ($channelIdentity === null || ! $channelIdentity->free_chat_allowed || ! $this->authorization->allows($user, 'agent.free_chat.use') || $this->costs->summary()['saving_mode'])) {
                return ErpAgentResponse::error('Não entendi o comando. Envie MENU para ver as opções.', 'command_not_understood');
            }
            $started = hrtime(true);
            $provider = class_basename($this->ai);
            $this->events->record('ai_provider_selected', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'selected', metadata: ['provider' => $provider]);
            try {
                $intent = $this->ai->interpret($message, array_keys($this->allowedTools($user)), $conversation->context ?? []);
            } catch (AiProviderUnavailableException) {
                $this->events->record('ai_provider_unavailable', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'unavailable', errorCode: 'ai_provider_unavailable', metadata: ['provider' => $provider]);

                return ErpAgentResponse::error('Serviço de interpretação por IA temporariamente indisponível.', 'ai_provider_unavailable');
            }
            $this->events->record('ai_called', $message->channel, $user, $conversation->id, $message->externalMessageId, $intent['tool'] ?? null);
            $this->costs->record('ai', 'ai_text', 'ai-text:'.$message->externalMessageId, $user, ['model' => config('agent_costs.models.text'), 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
        }
        if ($intent === null || ! isset($intent['tool'])) {
            return ErpAgentResponse::error('Não entendi o comando. Envie MENU para ver as opções.', 'command_not_understood');
        }
        if ($intent['tool'] === 'agent.operations.undo') {
            $intent['arguments'] = [...$this->undo->candidate($user), 'reason' => 'Cancelamento confirmado pelo usuário no agente.'];
        }
        $tool = $intent['tool'];
        unset($intent['tool']);

        return $this->runTool($tool, $intent['arguments'] ?? $intent, $user, $conversation, $message);
    }

    private function runTool(string $name, array $input, User $user, AgentConversation $conversation, AgentMessage $message): ErpAgentResponse
    {
        $tool = $this->registry->get($name) ?? throw new DomainException('Comando não disponível.');
        $this->authorization->authorize($user, $tool->permission);
        $input = $this->resolveLocation($input, $user);
        if ($tool->locationScoped && ! isset($input['location_id'])) {
            $action = $this->pending->prepare($user, $name, $input, ['location_id'], $message->externalMessageId.':location', $conversation->id);

            return new ErpAgentResponse(true, 'De qual unidade?', 'menu', options: $this->authorization->accessibleLocations($user)->map(fn ($location) => ['id' => $location->id, 'label' => $location->name])->all(), pendingAction: ['id' => $action->id]);
        }
        if ($tool->writesData && $tool->confirmationRequired) {
            $input['idempotency_key'] ??= $message->externalMessageId;
            $missing = $this->missing($name, $input);
            $action = $this->pending->prepare($user, $name, $input, $missing, $message->externalMessageId.':action', $conversation->id);
            if ($missing !== []) {
                return new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
            }

            return $this->confirmation($action);
        }
        $result = $this->executor->execute($name, $input, $user);
        $this->events->record('tool_executed', $message->channel, $user, $conversation->id, $message->externalMessageId, $name);

        return $this->format($name, $result, $input);
    }

    private function continuePending(PendingAgentAction $action, string $text, User $user, AgentMessage $message): ErpAgentResponse
    {
        $answer = mb_strtoupper(trim($text));
        if (in_array($answer, ['NÃO', 'NAO', 'CANCELAR'], true)) {
            $this->pending->cancel($action, $user);
            $this->events->record('confirmation_cancelled', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name);

            return new ErpAgentResponse(true, 'Operação cancelada.');
        }
        if (in_array($answer, ['SIM', 'CONFIRMAR'], true)) {
            $executed = $this->pending->confirm($action, $user, $this->executor);
            $this->events->record('confirmation_executed', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name);

            return new ErpAgentResponse(true, 'Operação confirmada e registrada com sucesso.', data: $executed->result ?? []);
        }
        if (preg_match('/(?:VALOR CORRETO|CORRIGIR(?: PARA)?)\D*([0-9]+(?:[.,][0-9]+)*)/ui', $text, $matches) === 1) {
            $field = array_key_exists('expected_amount', $action->payload) ? 'expected_amount' : (array_key_exists('amount', $action->payload) ? 'amount' : null);
            if ($field === null) {
                return ErpAgentResponse::error('Esta prévia não possui um valor corrigível por mensagem.', 'correction_not_supported');
            }
            $corrected = str_contains($matches[1], ',')
                ? str_replace(['.', ','], ['', '.'], $matches[1])
                : (preg_match('/\.\d{3}$/', $matches[1]) ? str_replace('.', '', $matches[1]) : $matches[1]);
            $action = $this->pending->merge($action, $user, [$field => $corrected], []);

            return $this->confirmation($action);
        }
        if (in_array('location_id', $action->missing_fields ?? [], true)) {
            $locations = $this->authorization->accessibleLocations($user)->filter(fn ($location) => str_contains(mb_strtolower($location->name), mb_strtolower($text)));
            if ($locations->count() !== 1) {
                return ErpAgentResponse::error('Unidade não encontrada ou ambígua. Informe o nome completo.', 'ambiguous_location');
            }
            $action = $this->pending->merge($action, $user, ['location_id' => $locations->first()->id], []);
            $missing = $this->missing($action->tool_name, $action->payload);
            $action = $this->pending->merge($action, $user, [], $missing);
            if ($missing !== []) {
                return new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
            }
            $tool = $this->registry->get($action->tool_name);
            if (! $tool->writesData) {
                $result = $this->executor->execute($action->tool_name, $action->payload, $user);
                $action->update(['status' => 'executed', 'executed_at' => now(), 'result' => ['completed' => true]]);

                return $this->format($action->tool_name, $result, $action->payload);
            }

            return $this->confirmation($action);
        }

        return ErpAgentResponse::error('A resposta não corresponde à ação pendente.', 'invalid_pending_answer');
    }

    private function confirmation(PendingAgentAction $action): ErpAgentResponse
    {
        $message = match ($action->tool_name) {
            'finance.payables.create' => $this->templates->payablePreview($action->payload),
            'agent.operations.undo' => "⚠️ CANCELAR OPERAÇÃO\n\n{$action->payload['operation_type']} #{$action->payload['operation_id']}\n\nDeseja realmente cancelar?",
            default => 'Revise os dados e confirme a operação. Confirmar?',
        };

        return new ErpAgentResponse(true, $message, 'confirmation', $action->payload, [['id' => 'yes', 'label' => 'SIM'], ['id' => 'no', 'label' => 'NÃO']], ['id' => $action->id]);
    }

    private function menu(User $user): ErpAgentResponse
    {
        $options = [];
        foreach ([['stock.view', 'Consultar estoque', 'ESTOQUE'], ['production.create', 'Registrar produção', 'PRODUÇÃO'], ['finance.view', 'Financeiro', 'FINANCEIRO HOJE'], ['purchases.view', 'Compras', 'COMPRAS']] as [$permission, $label, $command]) {
            if ($this->authorization->allows($user, $permission)) {
                $options[] = ['label' => $label, 'command' => $command];
            }
        }

        return new ErpAgentResponse(true, 'Olá, '.$user->name."! 👋\n\nO que você deseja fazer?", 'menu', options: $options);
    }

    private function format(string $name, mixed $result, array $input): ErpAgentResponse
    {
        return match ($name) {
            'stock.positions.list' => new ErpAgentResponse(true, $this->templates->stock($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'finance.payables.list' => new ErpAgentResponse(true, $this->templates->payables($result), data: ['count' => $result->count()]),
            'finance.reports.summary' => new ErpAgentResponse(true, $this->templates->finance($result), data: $result),
            default => new ErpAgentResponse(true, 'Consulta concluída.'),
        };
    }

    private function resolveLocation(array $input, User $user): array
    {
        $locations = $this->authorization->accessibleLocations($user);
        if (isset($input['location_name'])) {
            $matches = $locations->filter(fn ($location) => str_contains(mb_strtolower($location->name), mb_strtolower($input['location_name'])));
            if ($matches->count() === 1) {
                $input['location_id'] = $matches->first()->id;
            } unset($input['location_name']);
        }
        if (! isset($input['location_id']) && $locations->count() === 1) {
            $input['location_id'] = $locations->first()->id;
        }

        return $input;
    }

    private function missing(string $name, array $input): array
    {
        $required = match ($name) {
            'finance.payables.create' => ['description', 'location_id', 'expected_amount', 'competency_date', 'due_date'], 'finance.payments.record' => ['payable_id', 'amount', 'paid_at', 'financial_account_id', 'payment_method'], 'production.plan' => ['product_id', 'location_id', 'planned_quantity', 'operation_date'], 'purchases.documents.create' => ['document_type', 'issue_date', 'total_amount', 'location_id'], default => []
        };

        return array_values(array_filter($required, fn ($key) => ! isset($input[$key]) || $input[$key] === ''));
    }

    private function allowedTools(User $user): array
    {
        return array_filter($this->registry->all(), fn ($tool) => $this->authorization->allows($user, $tool->permission));
    }
}
