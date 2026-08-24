<?php

namespace App\Agent;

use App\Models\AgentAttachment;
use App\Models\AgentConversation;
use App\Models\AgentUsageCost;
use App\Models\Location;
use App\Models\Payable;
use App\Models\PendingAgentAction;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentAccessManagementService;
use App\Services\AgentCostService;
use App\Services\AgentEventService;
use App\Services\AiInterpretationService;
use App\Services\AuthorizationService;
use App\Services\DashboardUserVisibilityService;
use App\Services\RestrictedProductionInteractionService;
use App\Services\StockBalanceService;
use App\Services\UndoLastOperationService;
use App\Services\WhatsAppIdentityResolver;
use App\Support\DecimalFormatter;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Throwable;

class ErpAgentService
{
    public function __construct(private AgentConversationService $conversations, private PendingAgentActionService $pending, private CatalogAgentWorkflowService $catalogWorkflow, private DeterministicCommandParser $parser, private AiProviderInterface $ai, private AiInterpretationService $interpretations, private AgentToolRegistry $registry, private AgentToolExecutor $executor, private AuthorizationService $authorization, private AgentAccessManagementService $accessManagement, private DashboardUserVisibilityService $dashboardVisibility, private AgentResponseTemplate $templates, private AgentEventService $events, private UndoLastOperationService $undo, private AgentCostService $costs, private RestrictedProductionInteractionService $restrictedProduction, private WhatsAppIdentityResolver $identityResolver, private StockBalanceService $stockBalances) {}

    public function handle(AgentMessage $message): ErpAgentResponse
    {
        $resolution = $message->channel === 'whatsapp' ? $this->identityResolver->resolve($message->externalUserId) : null;
        $identity = $resolution?->identity ?? UserExternalIdentity::query()->with('user')->where('channel', $message->channel)->where('external_user_id', $message->externalUserId)->first();
        if ($message->channel !== 'whatsapp' && $identity === null) {
            UserExternalIdentity::query()->create(['channel' => $message->channel, 'external_user_id' => $message->externalUserId, 'display_name' => $message->metadata['display_name'] ?? null, 'status' => 'pending', 'active' => false, 'metadata' => ['first_message_type' => $message->messageType], 'last_contact_at' => now()]);

            return ErpAgentResponse::error('Usuário não identificado.', 'unknown_identity', 'unauthorized');
        }
        if ($identity === null || ($resolution !== null && ! $resolution->authorized()) || ! $identity->active || $identity->status !== 'approved' || $identity->user === null || ! $identity->user->active) {
            $code = $resolution?->status === 'invalid_identifier' ? 'unknown_identity' : ($resolution?->status ?? 'unknown_identity');

            return ErpAgentResponse::error('Acesso não autorizado.', $code, 'unauthorized');
        }
        if (($restrictedResponse = $this->restrictedProduction->guard($message, $identity)) !== null) {
            return $restrictedResponse;
        }
        $requiredPermission = match ($message->messageType) {
            'audio', 'transcribed_audio' => 'agent.audio.use', 'image' => 'agent.image.use', 'document' => 'agent.document.use', default => 'agent.text.use'
        };
        $identityFlag = match ($message->messageType) {
            'audio', 'transcribed_audio' => $identity->voice_allowed, 'image' => $identity->image_allowed, 'document' => $identity->document_allowed, default => $identity->structured_commands_allowed
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
            $response = $this->dispatch($message, $user, $conversation, $identity);
        } catch (AuthorizationException) {
            $this->events->record('action_denied', $message->channel, $user, $conversation->id, $message->externalMessageId);
            $response = ErpAgentResponse::error('Você não possui autorização para essa operação ou unidade.', 'forbidden', 'unauthorized');
        } catch (DomainException $exception) {
            $this->events->record('tool_validation_failed', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'failed', errorCode: 'validation_error');
            $response = ErpAgentResponse::error($exception->getMessage(), 'validation_error');
        } catch (Throwable) {
            $this->events->record('internal_error', $message->channel, $user, $conversation->id, $message->externalMessageId);
            $response = ErpAgentResponse::error('Não foi possível concluir a solicitação. Tente novamente.', 'internal_error');
        }
        $stored->update(['structured_payload' => [...($stored->structured_payload ?? []), 'response' => $response->toArray()]]);
        $this->conversations->message($conversation, 'assistant', $response->message, $response->toArray());

        return $response;
    }

    private function dispatch(AgentMessage $message, User $user, AgentConversation $conversation, UserExternalIdentity $identity): ErpAgentResponse
    {
        if ($message->messageType === 'audio') {
            return ErpAgentResponse::error(
                'Recebi a mídia, mas áudio, imagem e documento ainda não podem ser processados. Envie a informação em texto.',
                'media_processing_unavailable',
            );
        }

        if (in_array($message->messageType, ['image', 'document'], true) && $message->attachments === []) {
            return ErpAgentResponse::error('A mídia não possui um anexo privado autorizado.', 'media_attachment_required');
        }

        $text = trim($message->text ?? '');
        $staleActions = $conversation->pendingActions()->where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get(['id', 'tool_name']);
        $expired = $this->pending->expireStaleForConversation($conversation->id) > 0;
        if ($expired) {
            foreach ($staleActions as $staleAction) {
                $this->events->record('pending_expired', $message->channel, $user, $conversation->id, $message->externalMessageId, $staleAction->tool_name, ['domain' => $this->domain($staleAction->tool_name)], status: 'expired');
            }
        }
        $activeActions = $conversation->pendingActions()->where('status', 'pending')->latest()->get();
        if ($activeActions->count() > 1) {
            $this->events->record('pending_ambiguous', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'rejected', errorCode: 'multiple_pending_actions');

            return ErpAgentResponse::error('Há mais de uma ação pendente. Abra o painel do Agente e selecione explicitamente qual deseja revisar.', 'multiple_pending_actions');
        }
        $active = $activeActions->first();
        if ($active !== null) {
            return $this->continuePending($active, $text, $user, $message);
        }
        if ($expired && in_array(mb_strtoupper($text), ['1', 'SIM', 'OK', 'CONFIRMAR', 'PODE CRIAR'], true)) {
            return ErpAgentResponse::error('Esta ação expirou e não foi executada. Envie o comando novamente.', 'action_expired');
        }
        if (in_array(mb_strtoupper($text), ['OI', 'OLÁ', 'OLA', 'MENU', 'AJUDA'], true)) {
            return $this->menu($user);
        }
        $intent = $this->parser->parse($text);
        if ($intent !== null) {
            $this->events->record('deterministic_command', $message->channel, $user, $conversation->id, $message->externalMessageId, $intent['tool'] ?? null, ['action' => $intent['action'] ?? 'tool', 'submenu' => $intent['submenu'] ?? null]);
        }
        if ($intent === null) {
            $channelIdentity = UserExternalIdentity::query()->where('channel', $message->channel)->where('external_user_id', $message->externalUserId)->first();
            $testIntent = app()->environment('testing') && isset($message->metadata['fake_intent']);
            $freeTextDenied = in_array($message->messageType, ['text', 'interactive', 'transcribed_audio'], true) && ($channelIdentity === null || ! $channelIdentity->free_chat_allowed || ! $this->authorization->allows($user, 'agent.free_chat.use'));
            if (! $testIntent && ($freeTextDenied || $this->costs->summary()['saving_mode'])) {
                return ErpAgentResponse::error('Não entendi o comando. Envie MENU para ver as opções.', 'command_not_understood');
            }
            $started = hrtime(true);
            $provider = class_basename($this->ai);
            $this->events->record('ai_provider_selected', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'selected', metadata: ['provider' => $provider]);
            try {
                $interpretation = $this->interpretations->interpret($message, array_keys($this->allowedTools($user)), $user, $conversation->context ?? []);
            } catch (AiProviderResponseException) {
                $this->events->record('ai_response_invalid', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'rejected', errorCode: 'ai_response_invalid', metadata: ['provider' => $provider]);

                return ErpAgentResponse::error('A interpretação recebida não passou na validação. Reformule a solicitação ou tente mais tarde.', 'ai_response_invalid');
            } catch (AiProviderUnavailableException $exception) {
                $errorCode = $exception->getMessage();
                $this->events->record('ai_provider_unavailable', $message->channel, $user, $conversation->id, $message->externalMessageId, status: 'unavailable', errorCode: $errorCode, metadata: ['provider' => $provider]);

                return ErpAgentResponse::error('Serviço de interpretação por IA temporariamente indisponível.', 'ai_provider_unavailable');
            }
            if ($interpretation === null) {
                return ErpAgentResponse::error('Não foi possível interpretar a solicitação com segurança.', 'command_not_understood');
            }
            if (($restrictedResponse = $this->restrictedProduction->handleInterpretedImage($message, $identity, $interpretation, $conversation->id)) !== null) {
                if (! ($interpretation->usage['cached'] ?? false)) {
                    $this->costs->record('openai', 'ai_vision', 'ai:'.$message->externalMessageId, $user, [...$interpretation->usage, 'location_id' => $restrictedResponse->data['location_id'] ?? null, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'operation_type' => $interpretation->tool]);
                }

                return $restrictedResponse;
            }
            if ((float) $interpretation->confidence < (float) config('ai.minimum_confidence', '0.70')) {
                $this->events->record('ai_low_confidence', $message->channel, $user, $conversation->id, $message->externalMessageId, $interpretation->tool, status: 'rejected', errorCode: 'ai_low_confidence');

                return ErpAgentResponse::error('Não consegui identificar a solicitação com segurança. Informe os dados de outra forma.', 'ai_low_confidence');
            }
            $intent = ['tool' => $interpretation->tool, 'arguments' => [...$interpretation->fields, '_ai_missing_fields' => $interpretation->missingFields]];
            $this->events->record('ai_called', $message->channel, $user, $conversation->id, $message->externalMessageId, $intent['tool'] ?? null);
            if (! ($interpretation->usage['cached'] ?? false)) {
                $usageType = in_array($message->messageType, ['image', 'document'], true) ? 'ai_vision' : 'ai_text';
                $this->costs->record('openai', $usageType, 'ai:'.$message->externalMessageId, $user, [...$interpretation->usage, 'location_id' => $interpretation->fields['location_id'] ?? null, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'operation_type' => $interpretation->tool]);
            }
        }
        if ($intent === null || ! isset($intent['tool'])) {
            if (($intent['action'] ?? null) === 'use_location' && isset($intent['location_name'])) {
                return $this->useLocation($intent['location_name'], $user, $conversation);
            }
            if (($intent['action'] ?? null) === 'submenu' && isset($intent['submenu'])) {
                return $this->submenu($intent['submenu'], $user, $message, $conversation);
            }

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
        if ($message->channel === 'whatsapp' && $tool->writesData && ! $this->authorization->allows($user, 'agent.write.use')) {
            throw new AuthorizationException('Operações de escrita não estão liberadas neste canal.');
        }
        $this->authorization->authorize($user, $tool->permission);
        if ($name === 'production.orders.complete_batch') {
            $this->authorization->authorize($user, 'production.orders.create');
        }
        if (str_starts_with($name, 'dashboard.user_widgets.')) {
            $input = $this->dashboardVisibility->prepareAgentInput($name, $input, $user);
        }
        if ($tool->locationScoped && ! isset($input['location_id'], $input['location_name']) && isset($conversation->context['location_id'])) {
            $input['location_id'] = $conversation->context['location_id'];
        }
        $input = $this->resolveLocation($input, $user);
        if ($tool->locationScoped && isset($input['location_id'])) {
            $this->authorization->authorize($user, $tool->permission, (int) $input['location_id']);
            if ($name === 'production.orders.complete_batch') {
                $this->authorization->authorize($user, 'production.orders.create', (int) $input['location_id']);
            }
        }
        if (str_starts_with($name, 'agent.access.')) {
            $input = $this->accessManagement->prepareAgentInput($name, $input, $user);
        }
        if (isset($input['location_id'])) {
            AgentUsageCost::query()->where('idempotency_key', 'ai:'.$message->externalMessageId)
                ->whereNull('location_id')->update(['location_id' => $input['location_id']]);
        }
        if ($tool->locationScoped && ! isset($input['location_id'])) {
            $action = $this->pending->prepare($user, $name, $input, ['location_id'], $message->externalMessageId.':location', $conversation->id);

            return new ErpAgentResponse(true, 'De qual unidade?', 'menu', options: $this->authorization->accessibleLocations($user)->map(fn ($location) => ['id' => $location->id, 'label' => $location->name])->all(), pendingAction: ['id' => $action->id]);
        }
        if ($tool->writesData && $tool->confirmationRequired) {
            $sourceKey = $this->sourceKey($message);
            $input['idempotency_key'] ??= $sourceKey;
            $aiMissing = array_values(array_filter(
                $input['_ai_missing_fields'] ?? [],
                fn ($field) => ! isset($input[$field]) || $input[$field] === ''
            ));
            unset($input['_ai_missing_fields']);
            $missing = array_values(array_unique([...$this->missing($name, $input), ...$aiMissing]));
            $action = $this->pending->prepare($user, $name, $input, $missing, $sourceKey.':action', $conversation->id);
            $this->events->record('pending_created', $message->channel, $user, $conversation->id, $message->externalMessageId, $name, ['domain' => $this->domain($name), 'missing_count' => count($missing)], status: 'pending');
            if ($this->catalogWorkflow->supports($name)) {
                return $this->catalogWorkflow->question($action);
            }
            if ($missing !== []) {
                if ($name === 'purchases.documents.create' && in_array('received', $missing, true) && count($missing) === 1) {
                    return new ErpAgentResponse(true, 'A mercadoria deste documento já foi recebida fisicamente? Responda SIM ou NÃO.', pendingAction: ['id' => $action->id]);
                }
                if ($name === 'transfers.receive' && in_array('transfer_id', $missing, true)) {
                    return $this->transferQuestion($action, $user);
                }
                if (in_array('product_id', $missing, true)) {
                    return $this->productQuestion($action);
                }
                if (in_array('supplier_id', $missing, true) && isset($input['_supplier_match']['candidates'])) {
                    $names = collect($input['_supplier_match']['candidates'])->pluck('name')->implode(', ');

                    return new ErpAgentResponse(true, $names !== '' ? 'Encontrei fornecedores parecidos: '.$names.'. Qual deles é o correto?' : 'Não encontrei esse fornecedor cadastrado. A prévia ficará pendente até o vínculo manual.', 'confirmation', $action->payload, pendingAction: ['id' => $action->id]);
                }
                if ($name === 'purchases.documents.create') {
                    return new ErpAgentResponse(true, 'A prévia foi preservada, mas ainda precisa dos vínculos: '.implode(', ', $missing).'.', 'confirmation', $action->payload, pendingAction: ['id' => $action->id]);
                }
                if ($name === 'stock.opening_balance.record') {
                    return new ErpAgentResponse(true, 'Qual é a data real da contagem? Responda no formato AAAA-MM-DD.', pendingAction: ['id' => $action->id]);
                }

                return new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
            }

            return $this->confirmation($action);
        }
        try {
            $result = $this->executor->execute($name, $input, $user);
        } catch (Throwable $exception) {
            $this->events->record('tool_failed', $message->channel, $user, $conversation->id, $message->externalMessageId, $name, ['domain' => $this->domain($name)], status: 'failed', errorCode: class_basename($exception));
            throw $exception;
        }
        $conversation->update(['context' => array_filter([
            ...($conversation->context ?? []),
            'location_id' => $input['location_id'] ?? ($conversation->context['location_id'] ?? null),
            'last_tool' => $name,
        ], fn (mixed $value): bool => $value !== null)]);
        $this->events->record('tool_executed', $message->channel, $user, $conversation->id, $message->externalMessageId, $name, ['location_id' => $input['location_id'] ?? null, 'result' => 'success', 'domain' => $this->domain($name)], status: 'success');

        return $this->format($name, $result, $input);
    }

    private function continuePending(PendingAgentAction $action, string $text, User $user, AgentMessage $message): ErpAgentResponse
    {
        $answer = mb_strtoupper(trim($text));
        if (in_array('received', $action->missing_fields ?? [], true) && in_array($answer, ['SIM', 'NÃO', 'NAO'], true)) {
            $received = $answer === 'SIM';
            $payload = [...$action->payload, 'received' => $received];
            if ($received && ! isset($payload['received_date'])) {
                $payload['received_date'] = $payload['issue_date'] ?? now()->toDateString();
            }
            $missing = $this->missing($action->tool_name, $payload);
            $action = $this->pending->merge($action, $user, ['received' => $received, 'received_date' => $payload['received_date'] ?? null], $missing);

            return $missing === [] ? $this->confirmation($action) : new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
        }
        if ((empty($action->missing_fields) && $answer === '2') || in_array($answer, ['NÃO', 'NAO', 'CANCELAR'], true)) {
            $this->pending->cancel($action, $user);
            $this->events->record('confirmation_cancelled', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name, ['domain' => $this->domain($action->tool_name)], status: 'cancelled');

            return new ErpAgentResponse(true, 'Operação cancelada.');
        }
        if (empty($action->missing_fields) && in_array($answer, ['1', 'SIM', 'OK', 'CONFIRMAR', 'PODE CRIAR'], true)) {
            try {
                $executed = $this->pending->confirm($action, $user, $this->executor);
            } catch (Throwable $exception) {
                $this->events->record('tool_failed', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name, ['domain' => $this->domain($action->tool_name)], status: 'failed', errorCode: class_basename($exception));
                throw $exception;
            }
            $this->events->record('confirmation_executed', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name, ['domain' => $this->domain($action->tool_name)], status: 'success');

            return new ErpAgentResponse(true, 'Operação confirmada e registrada com sucesso.', data: $executed->result ?? []);
        }
        if ($this->catalogWorkflow->supports($action->tool_name)) {
            return $this->catalogWorkflow->collect($action, $text, $user);
        }
        if ($action->tool_name === 'stock.opening_balance.record') {
            if (in_array('operation_date', $action->missing_fields ?? [], true) && preg_match('/^(\d{4}-\d{2}-\d{2})$/', trim($text), $matches) === 1) {
                $payload = [...$action->payload, 'operation_date' => $matches[1]];
                $action = $this->pending->merge($action, $user, ['operation_date' => $matches[1]], $this->missing($action->tool_name, $payload));

                return empty($action->missing_fields) ? $this->confirmation($action) : new ErpAgentResponse(true, 'Informe a justificativa/origem da contagem real.', pendingAction: ['id' => $action->id]);
            }
            if (in_array('notes', $action->missing_fields ?? [], true) && trim($text) !== '') {
                $payload = [...$action->payload, 'notes' => trim($text)];
                $action = $this->pending->merge($action, $user, ['notes' => trim($text)], $this->missing($action->tool_name, $payload));

                return empty($action->missing_fields) ? $this->confirmation($action) : new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $action->missing_fields).'.', pendingAction: ['id' => $action->id]);
            }
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
        if (preg_match('/(?:QUANTIDADE(?: CORRETA)?|CORRIGIR QUANTIDADE(?: PARA)?|NA VERDADE FORAM)\D*([0-9]+(?:[.,][0-9]+)*)/ui', $text, $matches) === 1) {
            $field = collect(['quantity', 'actual_quantity', 'planned_quantity', 'quantity_received'])->first(fn ($candidate) => array_key_exists($candidate, $action->payload));
            if ($field === null) {
                return ErpAgentResponse::error('Esta prévia não possui quantidade simples corrigível.', 'correction_not_supported');
            }
            $action = $this->pending->merge($action, $user, [$field => str_replace(',', '.', $matches[1])], $this->missing($action->tool_name, [...$action->payload, $field => str_replace(',', '.', $matches[1])]));

            return $this->confirmation($action);
        }
        if (in_array('product_id', $action->missing_fields ?? [], true)) {
            $payload = $action->payload;
            if (isset($payload['_product_match'])) {
                $candidates = collect($payload['_product_match']['candidates'] ?? []);
                $choice = ctype_digit(trim($text)) ? (int) trim($text) : null;
                $selected = $choice !== null && $choice >= 1 && $choice <= $candidates->count()
                    ? $candidates->values()->get($choice - 1)
                    : $candidates->first(fn ($candidate) => mb_strtolower($candidate['name']) === mb_strtolower(trim($text)));
                if ($selected === null) {
                    return $this->productQuestion($action);
                }
                unset($payload['_product_match']);
                $payload['product_id'] = $selected['id'];
                $missing = $this->missing($action->tool_name, $payload);
                $action->update(['payload' => $payload, 'missing_fields' => $missing]);

                return $missing === [] ? $this->confirmation($action->refresh()) : new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
            }
            $index = collect($payload['items'] ?? [])->search(fn ($item) => ! isset($item['product_id']));
            if ($index === false) {
                return ErpAgentResponse::error('Nenhum produto pendente foi encontrado.', 'pending_product_not_found');
            }
            $candidates = collect(data_get($payload, "items.{$index}._product_match.candidates", []));
            $choice = ctype_digit(trim($text)) ? (int) trim($text) : null;
            $selected = $choice !== null && $choice >= 1 && $choice <= $candidates->count()
                ? $candidates->values()->get($choice - 1)
                : $candidates->first(fn ($candidate) => mb_strtolower($candidate['name']) === mb_strtolower(trim($text)));
            if ($selected === null) {
                return $this->productQuestion($action);
            }
            $payload['items'][$index]['product_id'] = $selected['id'];
            unset($payload['items'][$index]['_product_match']);
            $hasUnresolved = collect($payload['items'])->contains(fn ($item) => ! isset($item['product_id']));
            $missing = array_values(array_filter($action->missing_fields, fn ($field) => $field !== 'product_id' || $hasUnresolved));
            $action->update(['payload' => $payload, 'missing_fields' => $missing]);

            return $hasUnresolved ? $this->productQuestion($action->refresh()) : $this->confirmation($action->refresh());
        }
        if (in_array('transfer_id', $action->missing_fields ?? [], true)) {
            $candidates = collect($action->payload['_transfer_candidates'] ?? []);
            $choice = ctype_digit(trim($text)) ? (int) trim($text) : 0;
            $selected = $choice >= 1 && $choice <= $candidates->count() ? $candidates->values()->get($choice - 1) : null;
            if ($selected === null) {
                return $this->transferQuestion($action, $user);
            }
            $payload = $action->payload;
            unset($payload['_transfer_candidates']);
            $payload['transfer_id'] = $selected['id'];
            $missing = $this->missing($action->tool_name, $payload);
            $action->update(['payload' => $payload, 'missing_fields' => $missing]);

            return $missing === [] ? $this->confirmation($action->refresh()) : new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
        }
        if (preg_match('/(?:DATA|VENCIMENTO)(?: CORRETO| CORRETA)?\D*(\d{4}-\d{2}-\d{2})/ui', $text, $matches) === 1) {
            $field = str_contains(mb_strtoupper($text), 'VENCIMENTO') ? 'due_date' : collect(['operation_date', 'production_date', 'received_date', 'dispatch_date', 'paid_at'])->first(fn ($candidate) => array_key_exists($candidate, $action->payload));
            if ($field === null) {
                return ErpAgentResponse::error('Esta prévia não possui data corrigível.', 'correction_not_supported');
            }
            $payload = [...$action->payload, $field => $matches[1]];
            $action = $this->pending->merge($action, $user, [$field => $matches[1]], $this->missing($action->tool_name, $payload));

            return $this->confirmation($action);
        }
        if (in_array('location_id', $action->missing_fields ?? [], true)) {
            $normalizedText = mb_strtolower(trim($text));
            $locations = $this->authorization->accessibleLocations($user)->filter(function ($location) use ($normalizedText) {
                $normalizedName = mb_strtolower(trim($location->name));

                return str_contains($normalizedText, $normalizedName) || str_contains($normalizedName, $normalizedText);
            });
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
                $action->conversation?->update(['context' => [
                    ...($action->conversation->context ?? []),
                    'location_id' => $action->payload['location_id'],
                    'last_tool' => $action->tool_name,
                ]]);

                return $this->format($action->tool_name, $result, $action->payload);
            }

            return $this->confirmation($action);
        }
        if (in_array('supplier_id', $action->missing_fields ?? [], true)) {
            $candidateIds = collect(data_get($action->payload, '_supplier_match.candidates', []))->pluck('id')->map(fn ($id) => (int) $id);
            $matches = Supplier::query()->whereIn('id', $candidateIds)->get()->filter(fn (Supplier $supplier) => (string) $supplier->id === trim($text) || mb_strtolower(trim($supplier->name)) === mb_strtolower(trim($text)));
            if ($matches->count() !== 1) {
                return ErpAgentResponse::error('Fornecedor não encontrado ou ambíguo. Informe o nome completo de uma das opções.', 'ambiguous_supplier');
            }
            $payload = $action->payload;
            unset($payload['_supplier_match']);
            $payload['supplier_id'] = $matches->first()->id;
            $missing = $this->missing($action->tool_name, $payload);
            $action->update(['payload' => $payload, 'missing_fields' => $missing]);

            return $missing === [] ? $this->confirmation($action->refresh()) : new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
        }

        return ErpAgentResponse::error('A resposta não corresponde à ação pendente.', 'invalid_pending_answer');
    }

    private function confirmation(PendingAgentAction $action): ErpAgentResponse
    {
        if ($this->catalogWorkflow->supports($action->tool_name)) {
            return $this->catalogWorkflow->preview($action);
        }

        $message = match ($action->tool_name) {
            'finance.payables.create' => $this->templates->payablePreview($action->payload),
            'finance.payments.record' => $this->paymentPreview($action->payload),
            'agent.operations.undo' => "⚠️ CANCELAR OPERAÇÃO\n\n{$action->payload['operation_type']} #{$action->payload['operation_id']}\n\nDeseja realmente cancelar?",
            'production.orders.plan', 'production.orders.complete_batch' => $this->productionOrderPreview($action),
            'purchases.documents.create' => $this->purchasePreview($action),
            'purchases.receipts.receive' => $this->purchaseReceiptPreview($action->payload),
            'dashboard.user_widgets.update', 'dashboard.user_widgets.reset' => $this->dashboardVisibility->preview($action->tool_name, $action->payload),
            'agent.access.location.grant', 'agent.access.location.revoke', 'agent.access.locations.replace' => $this->accessManagement->preview($action->tool_name, $action->payload),
            'losses.record' => $this->lossPreview($action->payload),
            'transfers.create', 'transfers.complete', 'transfers.dispatch', 'transfers.receive' => $this->transferPreview($action->tool_name, $action->payload),
            'stock.opening_balance.record' => $this->openingStockPreview($action->payload),
            default => 'Revise os dados e confirme a operação. Confirmar?',
        };

        return new ErpAgentResponse(true, $message, 'confirmation', $action->payload, [['id' => 'yes', 'label' => 'SIM'], ['id' => 'no', 'label' => 'NÃO']], ['id' => $action->id]);
    }

    private function productQuestion(PendingAgentAction $action): ErpAgentResponse
    {
        $item = collect($action->payload['items'] ?? [])->first(fn ($item) => ! isset($item['product_id']));
        $candidates = collect(data_get($item, '_product_match.candidates', data_get($action->payload, '_product_match.candidates', [])));
        if ($candidates->isEmpty()) {
            return new ErpAgentResponse(true, 'Não encontrei esse produto cadastrado. Informe o nome exato pela interface administrativa.', pendingAction: ['id' => $action->id]);
        }
        $lines = ['Encontrei mais de uma possibilidade para "'.($item['product_name'] ?? $action->payload['product_name'] ?? 'produto').'":', ''];
        foreach ($candidates->values() as $index => $candidate) {
            $lines[] = ($index + 1).'. '.$candidate['name'];
        }
        $lines[] = '';
        $lines[] = 'Qual produto é o correto?';

        return new ErpAgentResponse(true, implode("\n", $lines), 'menu', options: $candidates->map(fn ($candidate) => ['id' => $candidate['id'], 'label' => $candidate['name']])->all(), pendingAction: ['id' => $action->id]);
    }

    private function productionOrderPreview(PendingAgentAction $action): string
    {
        $location = isset($action->payload['location_id']) ? Location::query()->find($action->payload['location_id'])?->name : 'Não informada';
        $lines = [$action->tool_name === 'production.orders.plan' ? '📋 PLANEJAMENTO DE PRODUÇÃO' : '🏭 PRODUÇÃO JÁ REALIZADA', '', 'Unidade: '.$location, 'Data: '.($action->payload['production_date'] ?? 'Não informada'), ''];
        foreach ($action->payload['items'] ?? [] as $item) {
            $name = isset($item['product_id']) ? Product::query()->find($item['product_id'])?->name : ($item['product_name'] ?? 'Produto pendente');
            $lines[] = $name.': '.($item['planned_quantity'] ?? $item['produced_quantity'] ?? $item['quantity'] ?? '?');
        }
        $lines[] = '';
        $lines[] = 'Confirmar?';

        return implode("\n", $lines);
    }

    private function purchasePreview(PendingAgentAction $action): string
    {
        $payload = $action->payload;
        $supplier = isset($payload['supplier_id']) ? Supplier::query()->find($payload['supplier_id'])?->name : 'Não identificado';
        $lines = [
            '🧾 PRÉVIA DA COMPRA',
            '',
            'Fornecedor: '.$supplier,
            'Documento: '.($payload['document_number'] ?? 'Sem número'),
            'Data: '.($payload['issue_date'] ?? 'Não informada'),
            'Total: R$ '.DecimalFormatter::format((string) ($payload['total_amount'] ?? '0'), 2),
            'Estoque: não será movimentado por este lançamento',
            '',
        ];
        foreach ($payload['items'] ?? [] as $item) {
            $lines[] = ($item['description'] ?? $item['ingredient_name'] ?? 'Item').' — '.($item['quantity'] ?? '?').' '.($item['unit'] ?? '').' — R$ '.DecimalFormatter::format((string) ($item['net_amount'] ?? $item['total_price'] ?? '0'), 2);
        }
        $lines[] = '';
        $lines[] = 'Confirma o lançamento?';

        return implode("\n", $lines);
    }

    private function transferQuestion(PendingAgentAction $action, User $user): ErpAgentResponse
    {
        $locationIds = $this->authorization->accessibleLocations($user)->pluck('id');
        $candidates = StockTransfer::query()->with(['sourceLocation', 'destinationLocation', 'items.product'])
            ->where('status', 'in_transit')->whereIn('destination_location_id', $locationIds)->latest('dispatched_date')->limit(10)->get();
        if ($candidates->isEmpty()) {
            return new ErpAgentResponse(true, 'Não há transferência em trânsito para uma unidade autorizada.', pendingAction: ['id' => $action->id]);
        }
        $stored = $candidates->map(fn ($transfer) => ['id' => $transfer->id])->all();
        $action->update(['payload' => [...$action->payload, '_transfer_candidates' => $stored]]);
        $lines = ['Qual transferência foi recebida?', ''];
        foreach ($candidates->values() as $index => $transfer) {
            $lines[] = ($index + 1).'. '.$transfer->sourceLocation->name.' → '.$transfer->destinationLocation->name;
            foreach ($transfer->items as $item) {
                $lines[] = $item->product->name.': '.$item->quantity_sent.' '.$item->product->stock_unit;
            }
            $lines[] = '';
        }

        return new ErpAgentResponse(true, implode("\n", $lines), 'menu', pendingAction: ['id' => $action->id]);
    }

    private function menu(User $user): ErpAgentResponse
    {
        $options = [];
        foreach ([
            [['stock.view'], 'Consultar estoque', 'ESTOQUE'],
            [['production.view', 'production.orders.create', 'production.orders.complete', 'production_requirements.view'], 'Produção', 'PRODUÇÃO'],
            [['finance.payables.view', 'finance.payments.view', 'finance.reports.view'], 'Financeiro', 'FINANCEIRO'],
            [['purchases.view'], 'Compras', 'COMPRAS'],
            [['transfers.view'], 'Transferências', 'TRANSFERÊNCIAS'],
            [['reports.view'], 'Relatório operacional', 'RELATÓRIO OPERACIONAL'],
        ] as [$permissions, $label, $command]) {
            if ($this->allowsAny($user, $permissions)) {
                $options[] = ['label' => $label, 'command' => $command];
            }
        }

        $greeting = match (true) {
            now()->hour < 12 => 'Bom dia',
            now()->hour < 18 => 'Boa tarde',
            default => 'Boa noite',
        };

        return new ErpAgentResponse(true, $greeting.', '.$user->name."! 👋\n\nO que você deseja fazer?", 'menu', options: $options);
    }

    private function submenu(string $name, User $user, AgentMessage $message, AgentConversation $conversation): ErpAgentResponse
    {
        $definitions = match ($name) {
            'production' => [
                ['production.view', 'Produção de hoje', 'PRODUÇÃO HOJE'],
                [['production.orders.create', 'production.orders.complete'], 'Registrar: PRODUZIMOS <quantidade> <produto>', null],
                ['production_requirements.view', 'Produção sugerida', 'PRODUÇÃO SUGERIDA'],
            ],
            'purchases' => [
                ['purchases.view', 'Documentos recentes', 'DOCUMENTOS RECENTES'],
                ['purchases.view', 'Consultar: DOCUMENTO <número>', null],
                ['purchases.view', 'Itens: ITENS DOCUMENTO <número>', null],
            ],
            'finance' => [
                ['finance.payables.view', 'Contas a pagar', 'CONTAS A PAGAR'],
                ['finance.payables.view', 'Contas vencidas', 'CONTAS VENCIDAS'],
                ['finance.reports.view', 'Financeiro de hoje', 'FINANCEIRO HOJE'],
                ['finance.reports.view', 'Financeiro do mês', 'FINANCEIRO MÊS'],
            ],
            'transfers' => [
                ['transfers.view', 'Transferências recentes', 'TRANSFERÊNCIAS RECENTES'],
                ['transfers.view', 'Em trânsito', 'TRANSFERÊNCIAS EM TRÂNSITO'],
                ['transfers.view', 'Pendentes de recebimento', 'PENDENTES DE RECEBIMENTO'],
            ],
            default => [],
        };
        $options = collect($definitions)
            ->filter(fn (array $definition) => is_array($definition[0])
                ? collect($definition[0])->every(fn (string $permission) => $this->authorization->allows($user, $permission))
                : $this->authorization->allows($user, $definition[0]))
            ->map(fn (array $definition) => ['label' => $definition[1], 'command' => $definition[2]])
            ->values()
            ->all();
        if ($options === []) {
            return ErpAgentResponse::error('Nenhuma opção deste menu está autorizada para o seu usuário.', 'submenu_not_authorized', 'unauthorized');
        }
        $this->events->record('submenu_opened', $message->channel, $user, $conversation->id, $message->externalMessageId, metadata: ['submenu' => $name], status: 'success');

        return new ErpAgentResponse(true, 'Escolha uma opção:', 'menu', options: $options);
    }

    private function format(string $name, mixed $result, array $input): ErpAgentResponse
    {
        return match ($name) {
            'sales.summary' => new ErpAgentResponse(true, $this->templates->salesSummary($result), data: $result),
            'sales.products.ranking' => new ErpAgentResponse(true, $this->templates->productRanking($result), data: $result),
            'sales.payments.summary' => new ErpAgentResponse(true, $this->templates->paymentSummary($result), data: $result),
            'stock.products.query' => new ErpAgentResponse(true, $this->templates->productStockQuery($result), data: $result),
            'stock.ingredients.query' => new ErpAgentResponse(true, $this->templates->ingredientStockQuery($result), data: $result),
            'pdv.health' => new ErpAgentResponse(true, $this->templates->pdvHealth($result), data: $result),
            'pdv.reconciliation' => new ErpAgentResponse(true, $this->templates->pdvReconciliation($result), data: $result),
            'products.prices.query' => new ErpAgentResponse(true, $this->templates->catalogPrices($result), data: $result),
            'products.catalog.query' => new ErpAgentResponse(true, $this->templates->catalogProducts($result), data: $result),
            'ingredients.catalog.query' => new ErpAgentResponse(true, $this->templates->catalogIngredients($result), data: $result),
            'suppliers.catalog.query' => new ErpAgentResponse(true, $this->templates->suppliers($result), data: $result),
            'stock.positions.list' => new ErpAgentResponse(true, $this->templates->stock($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'ingredient_stock.positions.list' => new ErpAgentResponse(true, $this->templates->ingredientStock($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'ingredient_stock.shortages.list' => new ErpAgentResponse(true, $this->templates->ingredientShortages($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'production.today' => new ErpAgentResponse(true, $this->templates->productions($result, Location::query()->findOrFail($input['location_id'])->name, $input['date'] ?? now()->toDateString()), data: ['count' => $result->count()]),
            'production.orders.query' => new ErpAgentResponse(true, $this->templates->productionOrders($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => $result->count()]),
            'production.suggestions.list' => new ErpAgentResponse(true, $this->templates->productionSuggestions($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => count($result)]),
            'transfers.list' => new ErpAgentResponse(true, $this->templates->transfers($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => $result->count()]),
            'losses.query' => new ErpAgentResponse(true, $this->templates->losses($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => $result->count()]),
            'reports.operational.summary' => new ErpAgentResponse(true, $this->templates->operational($result, Location::query()->findOrFail($input['location_id'])->name), data: $result),
            'finance.payables.list' => new ErpAgentResponse(true, $this->templates->payables($result), data: ['count' => $result->count()]),
            'finance.payables.get' => new ErpAgentResponse(true, $this->templates->payable($result), data: ['id' => $result->id]),
            'finance.payments.list' => new ErpAgentResponse(true, $this->templates->payments($result), data: ['count' => $result->count()]),
            'finance.accounts.list' => new ErpAgentResponse(true, $this->templates->financialAccounts($result), data: ['count' => $result->count()]),
            'finance.reports.summary' => new ErpAgentResponse(true, $this->templates->finance($result), data: $result),
            'purchases.documents.list' => new ErpAgentResponse(true, $this->templates->purchases($result), data: ['count' => $result->count()]),
            'purchases.documents.get' => new ErpAgentResponse(true, $this->templates->purchase($result), data: ['id' => $result->id]),
            'purchases.items.list' => new ErpAgentResponse(true, $this->templates->purchaseItems($result), data: ['count' => $result->count()]),
            'purchases.history' => new ErpAgentResponse(true, $this->templates->purchases($result), data: ['count' => $result->count()]),
            'purchases.summary' => new ErpAgentResponse(true, $this->templates->purchaseSummary($result), data: $result),
            'dashboard.user_widgets.list' => new ErpAgentResponse(true, $this->dashboardVisibility->describe($result), data: $result),
            'agent.access.locations.list' => new ErpAgentResponse(true, 'Unidades de '.$result['target_user_name'].': '.(collect($result['locations'])->pluck('name')->implode(', ') ?: 'nenhuma unidade').'.', data: $result),
            default => new ErpAgentResponse(true, 'Consulta concluída.'),
        };
    }

    private function resolveLocation(array $input, User $user): array
    {
        $locations = $this->authorization->accessibleLocations($user);
        if (isset($input['location_name'])) {
            $normalizedName = $this->normalize((string) $input['location_name']);
            $matches = $locations->filter(function ($location) use ($normalizedName) {
                $locationName = $this->normalize($location->name);

                return str_contains($normalizedName, $locationName) || str_contains($locationName, $normalizedName);
            });
            if ($matches->count() === 0) {
                throw new AuthorizationException('Unidade não autorizada.');
            }
            if ($matches->count() > 1) {
                throw new DomainException('A unidade informada é ambígua. Informe o nome completo.');
            }
            $input['location_id'] = $matches->first()->id;
            unset($input['location_name']);
        }
        foreach (['source', 'destination'] as $side) {
            $nameKey = $side.'_location_name';
            if (! isset($input[$nameKey])) {
                continue;
            }
            $normalizedName = $this->normalize((string) $input[$nameKey]);
            $matches = $locations->filter(function ($location) use ($normalizedName) {
                $locationName = $this->normalize($location->name);

                return str_contains($normalizedName, $locationName) || str_contains($locationName, $normalizedName);
            });
            if ($matches->count() === 0) {
                throw new AuthorizationException('Unidade não autorizada.');
            }
            if ($matches->count() > 1) {
                throw new DomainException('A unidade informada é ambígua. Informe o nome completo.');
            }
            $input[$side.'_location_id'] = $matches->first()->id;
            unset($input[$nameKey]);
        }
        if (! isset($input['location_id']) && $user->default_location_id !== null && $locations->contains('id', $user->default_location_id)) {
            $input['location_id'] = $user->default_location_id;
        }
        if (! isset($input['location_id']) && $locations->count() === 1) {
            $input['location_id'] = $locations->first()->id;
        }

        return $input;
    }

    private function useLocation(string $name, User $user, AgentConversation $conversation): ErpAgentResponse
    {
        $resolved = $this->resolveLocation(['location_name' => $name], $user);
        if (! isset($resolved['location_id'])) {
            return ErpAgentResponse::error('Unidade não encontrada ou ambígua. Informe o nome completo.', 'ambiguous_location');
        }
        $location = Location::query()->findOrFail($resolved['location_id']);
        $conversation->update(['context' => [...($conversation->context ?? []), 'location_id' => $location->id]]);

        return new ErpAgentResponse(true, 'Unidade ativa no Agente: '.$location->name.'.', data: ['location_id' => $location->id]);
    }

    private function normalize(string $value): string
    {
        return Str::squish(Str::lower(Str::ascii($value)));
    }

    /** @param array<string, mixed> $payload */
    private function paymentPreview(array $payload): string
    {
        $payable = Payable::query()->with('supplier')->findOrFail($payload['payable_id']);
        $remaining = BigDecimal::of($payable->expected_amount)->minus($payable->paidAmount());
        $after = $remaining->minus((string) $payload['amount']);

        return "PAGAMENTO\n\nConta: {$payable->description}\nFornecedor: ".($payable->supplier?->name ?? 'Não informado')."\nValor: R$ ".DecimalFormatter::format((string) $payload['amount'], 2)."\nSaldo atual: R$ ".DecimalFormatter::format((string) $remaining, 2)."\nSaldo após confirmação: R$ ".DecimalFormatter::format((string) $after, 2)."\nData real: {$payload['paid_at']}\nMétodo: {$payload['payment_method']}\n\nConfirmar?";
    }

    /** @param array<string, mixed> $payload */
    private function purchaseReceiptPreview(array $payload): string
    {
        $document = PurchaseDocument::query()->with(['supplier', 'location', 'items'])->findOrFail($payload['document_id']);
        $lines = ['RECEBIMENTO DE COMPRA', '', 'Documento: #'.$document->id.' '.($document->document_number ?? ''), 'Fornecedor: '.($document->supplier?->name ?? 'Não informado'), 'Unidade: '.$document->location->name, 'Data real: '.$payload['received_date'], ''];
        foreach ($payload['items'] as $row) {
            $item = $document->items->firstWhere('id', (int) ($row['item_id'] ?? 0));
            $lines[] = ($item?->description ?? 'Item inválido').': '.($row['quantity'] ?? '?').' '.($item?->unit ?? '');
        }
        $lines[] = '';
        $lines[] = 'O estoque de insumos será movimentado somente após esta confirmação.';
        $lines[] = 'Confirmar?';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $payload */
    private function lossPreview(array $payload): string
    {
        $location = Location::query()->findOrFail($payload['location_id']);
        $product = Product::query()->findOrFail($payload['product_id']);
        $before = BigDecimal::of($this->stockBalances->balance($product->id, $location->id));
        $after = $before->minus((string) $payload['quantity']);

        return "PERDA DE PRODUTO\n\nProduto: {$product->name}\nUnidade: {$location->name}\nQuantidade: {$payload['quantity']} {$product->stock_unit}\nSaldo atual: {$before} {$product->stock_unit}\nSaldo após confirmação: {$after} {$product->stock_unit}\nData real: {$payload['operation_date']}\n\nO saldo será revalidado antes da gravação. Confirmar?";
    }

    /** @param array<string, mixed> $payload */
    private function transferPreview(string $tool, array $payload): string
    {
        if (isset($payload['transfer_id'])) {
            $transfer = StockTransfer::query()->with(['sourceLocation', 'destinationLocation', 'items.product'])->findOrFail($payload['transfer_id']);
            $lines = [$tool === 'transfers.dispatch' ? 'EXPEDIÇÃO DE TRANSFERÊNCIA' : 'RECEBIMENTO DE TRANSFERÊNCIA', '', 'Transferência: #'.$transfer->id, 'Origem: '.$transfer->sourceLocation->name, 'Destino: '.$transfer->destinationLocation->name, ''];
            foreach ($transfer->items as $item) {
                $quantity = $tool === 'transfers.receive' ? ($payload['quantity_received'] ?? '?') : $item->quantity_sent;
                $lines[] = $item->product->name.': '.$quantity.' '.$item->product->stock_unit;
            }
            $lines[] = '';
            $lines[] = $tool === 'transfers.dispatch'
                ? 'A saída da origem ocorrerá após confirmação; o destino ainda não será incrementado.'
                : 'A entrada no destino ocorrerá após confirmação; a saída da origem não será repetida.';
            $lines[] = 'Confirmar?';

            return implode("\n", $lines);
        }

        $source = Location::query()->find($payload['source_location_id'])?->name ?? 'Não informada';
        $destination = Location::query()->find($payload['destination_location_id'])?->name ?? 'Não informada';
        $product = Product::query()->find($payload['product_id']);
        $sourceBefore = BigDecimal::of($this->stockBalances->balance((int) $payload['product_id'], (int) $payload['source_location_id']));
        $destinationBefore = BigDecimal::of($this->stockBalances->balance((int) $payload['product_id'], (int) $payload['destination_location_id']));
        $lines = ['TRANSFERÊNCIA DE ESTOQUE', '', 'Origem: '.$source, 'Destino: '.$destination, 'Produto: '.($product?->name ?? 'Não informado'), 'Quantidade: '.$payload['quantity'].' '.($product?->stock_unit ?? ''), 'Saldo atual na origem: '.$sourceBefore, 'Saldo atual no destino: '.$destinationBefore, ''];
        $lines[] = $tool === 'transfers.create'
            ? 'A confirmação criará a transferência pendente. Nenhum saldo será movimentado até a expedição.'
            : 'A confirmação executará saída e recebimento oficiais de forma idempotente.';
        $lines[] = 'Confirmar?';

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $payload */
    private function openingStockPreview(array $payload): string
    {
        $location = Location::query()->find($payload['location_id'])?->name ?? 'Não informada';
        $product = Product::query()->find($payload['product_id'])?->name ?? 'Não informado';

        return "ESTOQUE INICIAL\n\nProduto:\n{$product}\n\nUnidade/localização:\n{$location}\n\nQuantidade:\n{$payload['quantity']}\n\nData real:\n{$payload['operation_date']}\n\nJustificativa:\n{$payload['notes']}\n\nSerá criado um movimento oficial, imutável e idempotente. Confirmar?";
    }

    private function missing(string $name, array $input): array
    {
        $required = match ($name) {
            'catalog.products.create' => ['name', 'selling_price'],
            'catalog.products.update' => ['product_id'],
            'catalog.products.update_price' => ['product_id', 'selling_price'],
            'catalog.product_aliases.create' => ['product_id', 'alias'],
            'catalog.suppliers.create' => ['name'],
            'catalog.suppliers.update' => ['supplier_id'],
            'catalog.ingredients.create' => ['name', 'base_unit'],
            'catalog.ingredients.update' => ['ingredient_id'],
            'catalog.ingredient_prices.add' => ['ingredient_id', 'supplier_id', 'purchase_quantity', 'purchase_unit', 'price_paid', 'effective_date'],
            'catalog.preparations.create' => ['name', 'expected_yield', 'yield_unit', 'total_preparation_time_minutes'],
            'catalog.preparations.update' => ['preparation_id'],
            'catalog.product_recipes.create', 'catalog.product_recipes.update' => ['product_id', 'yield_quantity', 'technical_loss_percentage', 'packaging_cost'],
            'stock.opening_balance.record' => ['product_id', 'location_id', 'quantity', 'operation_date', 'notes'],
            'production.orders.plan', 'production.orders.complete_batch' => ['location_id', 'production_date', 'items'],
            'finance.payables.create' => ['description', 'location_id', 'expected_amount', 'competency_date', 'due_date'],
            'finance.payments.record' => ['payable_id', 'amount', 'paid_at', 'financial_account_id', 'payment_method'],
            'losses.record' => ['product_id', 'location_id', 'loss_reason_id', 'quantity', 'operation_date'],
            'transfers.create', 'transfers.complete' => ['source_location_id', 'destination_location_id', 'product_id', 'quantity', 'operation_date'],
            'transfers.dispatch' => ['transfer_id', 'dispatch_date'],
            'transfers.receive' => ['transfer_id', 'received_date', 'quantity_received'],
            'agent.access.location.grant', 'agent.access.location.revoke', 'agent.access.default_location.set', 'agent.access.locations.replace' => ['target_user_id', 'location_id'],
            'purchases.documents.create' => ['document_type', 'issue_date', 'total_amount', 'location_id', 'supplier_id', 'items'],
            'purchases.receipts.receive' => ['document_id', 'received_date', 'items'],
            default => []
        };

        return array_values(array_filter($required, fn ($key) => ! isset($input[$key]) || $input[$key] === ''));
    }

    private function allowedTools(User $user): array
    {
        return array_filter($this->registry->all(), fn ($tool) => $this->authorization->allows($user, $tool->permission)
            && ($tool->name !== 'production.orders.complete_batch' || $this->authorization->allows($user, 'production.orders.create')));
    }

    private function sourceKey(AgentMessage $message): string
    {
        if (in_array($message->messageType, ['image', 'document', 'transcribed_audio'], true) && count($message->attachments) === 1) {
            $reference = $message->attachments[0];
            $id = is_array($reference) ? ($reference['id'] ?? null) : $reference;
            $hash = AgentAttachment::query()->whereKey($id)->value('content_hash');
            if (filled($hash)) {
                return 'attachment:'.$hash;
            }
        }

        return $message->externalMessageId;
    }

    private function domain(string $tool): string
    {
        return match (true) {
            str_starts_with($tool, 'catalog.products'), str_starts_with($tool, 'products.') => 'products',
            str_starts_with($tool, 'catalog.ingredients'), str_starts_with($tool, 'ingredients.'), str_starts_with($tool, 'costs.ingredients') => 'ingredients',
            str_starts_with($tool, 'catalog.suppliers'), str_starts_with($tool, 'suppliers.') => 'suppliers',
            str_starts_with($tool, 'purchases.') => 'purchases',
            str_starts_with($tool, 'finance.') => 'finance',
            str_starts_with($tool, 'production.') => 'production',
            str_starts_with($tool, 'losses.') => 'losses',
            str_starts_with($tool, 'transfers.') => 'transfers',
            default => 'erp',
        };
    }

    /** @param array<int, string> $permissions */
    private function allowsAny(User $user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $this->authorization->allows($user, $permission));
    }
}
