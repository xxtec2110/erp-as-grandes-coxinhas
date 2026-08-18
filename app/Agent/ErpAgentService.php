<?php

namespace App\Agent;

use App\Models\AgentAttachment;
use App\Models\AgentConversation;
use App\Models\AgentUsageCost;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Services\AgentCostService;
use App\Services\AgentEventService;
use App\Services\AiInterpretationService;
use App\Services\AuthorizationService;
use App\Services\DashboardUserVisibilityService;
use App\Services\RestrictedProductionInteractionService;
use App\Services\UndoLastOperationService;
use App\Services\WhatsAppIdentityResolver;
use App\Support\DecimalFormatter;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

class ErpAgentService
{
    public function __construct(private AgentConversationService $conversations, private PendingAgentActionService $pending, private CatalogAgentWorkflowService $catalogWorkflow, private DeterministicCommandParser $parser, private AiProviderInterface $ai, private AiInterpretationService $interpretations, private AgentToolRegistry $registry, private AgentToolExecutor $executor, private AuthorizationService $authorization, private DashboardUserVisibilityService $dashboardVisibility, private AgentResponseTemplate $templates, private AgentEventService $events, private UndoLastOperationService $undo, private AgentCostService $costs, private RestrictedProductionInteractionService $restrictedProduction, private WhatsAppIdentityResolver $identityResolver) {}

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
        $active = $conversation->pendingActions()->where('status', 'pending')->latest()->first();
        if ($active !== null) {
            return $this->continuePending($active, $text, $user, $message);
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
        if (str_starts_with($name, 'dashboard.user_widgets.')) {
            $input = $this->dashboardVisibility->prepareAgentInput($name, $input, $user);
        }
        $input = $this->resolveLocation($input, $user);
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

                return new ErpAgentResponse(true, 'Preciso informar: '.implode(', ', $missing).'.', pendingAction: ['id' => $action->id]);
            }

            return $this->confirmation($action);
        }
        $result = $this->executor->execute($name, $input, $user);
        $this->events->record('tool_executed', $message->channel, $user, $conversation->id, $message->externalMessageId, $name, ['location_id' => $input['location_id'] ?? null, 'result' => 'success'], status: 'success');

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
            $this->events->record('confirmation_cancelled', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name);

            return new ErpAgentResponse(true, 'Operação cancelada.');
        }
        if (empty($action->missing_fields) && in_array($answer, ['1', 'SIM', 'OK', 'CONFIRMAR', 'PODE CRIAR'], true)) {
            $executed = $this->pending->confirm($action, $user, $this->executor);
            $this->events->record('confirmation_executed', $message->channel, $user, $action->agent_conversation_id, $message->externalMessageId, $action->tool_name);

            return new ErpAgentResponse(true, 'Operação confirmada e registrada com sucesso.', data: $executed->result ?? []);
        }
        if ($this->catalogWorkflow->supports($action->tool_name)) {
            return $this->catalogWorkflow->collect($action, $text, $user);
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
            'agent.operations.undo' => "⚠️ CANCELAR OPERAÇÃO\n\n{$action->payload['operation_type']} #{$action->payload['operation_id']}\n\nDeseja realmente cancelar?",
            'production.orders.plan', 'production.orders.complete_batch' => $this->productionOrderPreview($action),
            'purchases.documents.create' => $this->purchasePreview($action),
            'dashboard.user_widgets.update', 'dashboard.user_widgets.reset' => $this->dashboardVisibility->preview($action->tool_name, $action->payload),
            default => 'Revise os dados e confirme a operação. Confirmar?',
        };

        return new ErpAgentResponse(true, $message, 'confirmation', $action->payload, [['id' => 'yes', 'label' => 'SIM'], ['id' => 'no', 'label' => 'NÃO']], ['id' => $action->id]);
    }

    private function productQuestion(PendingAgentAction $action): ErpAgentResponse
    {
        $item = collect($action->payload['items'] ?? [])->first(fn ($item) => ! isset($item['product_id']));
        $candidates = collect(data_get($item, '_product_match.candidates', []));
        if ($candidates->isEmpty()) {
            return new ErpAgentResponse(true, 'Não encontrei esse produto cadastrado. Informe o nome exato pela interface administrativa.', pendingAction: ['id' => $action->id]);
        }
        $lines = ['Encontrei mais de uma possibilidade para "'.($item['product_name'] ?? 'produto').'":', ''];
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
            'Recebimento físico: '.(($payload['received'] ?? false) ? 'Sim' : 'Não'),
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
            [['production.view', 'production.create', 'production_requirements.view'], 'Produção', 'PRODUÇÃO'],
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
                ['production.create', 'Planejar: PRODUZIMOS <quantidade> <produto>', null],
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
            ->filter(fn (array $definition) => $this->authorization->allows($user, $definition[0]))
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
            'stock.positions.list' => new ErpAgentResponse(true, $this->templates->stock($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'ingredient_stock.positions.list' => new ErpAgentResponse(true, $this->templates->ingredientStock($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'ingredient_stock.shortages.list' => new ErpAgentResponse(true, $this->templates->ingredientShortages($result, Location::query()->findOrFail($input['location_id'])->name), data: ['items' => $result]),
            'production.today' => new ErpAgentResponse(true, $this->templates->productions($result, Location::query()->findOrFail($input['location_id'])->name, $input['date'] ?? now()->toDateString()), data: ['count' => $result->count()]),
            'production.suggestions.list' => new ErpAgentResponse(true, $this->templates->productionSuggestions($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => count($result)]),
            'transfers.list' => new ErpAgentResponse(true, $this->templates->transfers($result, Location::query()->findOrFail($input['location_id'])->name), data: ['count' => $result->count()]),
            'reports.operational.summary' => new ErpAgentResponse(true, $this->templates->operational($result, Location::query()->findOrFail($input['location_id'])->name), data: $result),
            'finance.payables.list' => new ErpAgentResponse(true, $this->templates->payables($result), data: ['count' => $result->count()]),
            'finance.reports.summary' => new ErpAgentResponse(true, $this->templates->finance($result), data: $result),
            'purchases.documents.list' => new ErpAgentResponse(true, $this->templates->purchases($result), data: ['count' => $result->count()]),
            'purchases.documents.get' => new ErpAgentResponse(true, $this->templates->purchase($result), data: ['id' => $result->id]),
            'purchases.items.list' => new ErpAgentResponse(true, $this->templates->purchaseItems($result), data: ['count' => $result->count()]),
            'dashboard.user_widgets.list' => new ErpAgentResponse(true, $this->dashboardVisibility->describe($result), data: $result),
            default => new ErpAgentResponse(true, 'Consulta concluída.'),
        };
    }

    private function resolveLocation(array $input, User $user): array
    {
        $locations = $this->authorization->accessibleLocations($user);
        if (isset($input['location_name'])) {
            $normalizedName = mb_strtolower(trim((string) $input['location_name']));
            $matches = $locations->filter(function ($location) use ($normalizedName) {
                $locationName = mb_strtolower(trim($location->name));

                return str_contains($normalizedName, $locationName) || str_contains($locationName, $normalizedName);
            });
            if ($matches->count() === 1) {
                $input['location_id'] = $matches->first()->id;
            } unset($input['location_name']);
        }
        if (! isset($input['location_id']) && $user->default_location_id !== null && $locations->contains('id', $user->default_location_id)) {
            $input['location_id'] = $user->default_location_id;
        }
        if (! isset($input['location_id']) && $locations->count() === 1) {
            $input['location_id'] = $locations->first()->id;
        }

        return $input;
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
            'production.orders.plan', 'production.orders.complete_batch' => ['location_id', 'production_date', 'items'], 'finance.payables.create' => ['description', 'location_id', 'expected_amount', 'competency_date', 'due_date'], 'finance.payments.record' => ['payable_id', 'amount', 'paid_at', 'financial_account_id', 'payment_method'], 'production.plan' => ['product_id', 'location_id', 'planned_quantity', 'operation_date'], 'production.complete' => ['production_id', 'actual_quantity'], 'losses.record' => ['product_id', 'location_id', 'loss_reason_id', 'quantity', 'operation_date'], 'transfers.create' => ['source_location_id', 'destination_location_id', 'product_id', 'quantity', 'operation_date'], 'transfers.dispatch' => ['transfer_id', 'dispatch_date'], 'transfers.receive' => ['transfer_id', 'received_date', 'quantity_received'], 'purchases.documents.create' => ['document_type', 'issue_date', 'total_amount', 'location_id', 'supplier_id', 'items', 'received'], default => []
        };

        return array_values(array_filter($required, fn ($key) => ! isset($input[$key]) || $input[$key] === ''));
    }

    private function allowedTools(User $user): array
    {
        return array_filter($this->registry->all(), fn ($tool) => $this->authorization->allows($user, $tool->permission));
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

    /** @param array<int, string> $permissions */
    private function allowsAny(User $user, array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $this->authorization->allows($user, $permission));
    }
}
