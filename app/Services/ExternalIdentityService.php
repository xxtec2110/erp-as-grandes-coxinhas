<?php

namespace App\Services;

use App\Models\AgentConversation;
use App\Models\PendingAgentAction;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\WhatsApp\FakeWhatsAppClient;
use App\WhatsApp\WhatsAppClientInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ExternalIdentityService
{
    public function __construct(private PhoneNumberNormalizer $phones, private AgentEventService $events, private WhatsAppClientInterface $whatsApp) {}

    public function create(array $data, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($data, $actor): UserExternalIdentity {
            $user = User::query()->lockForUpdate()->findOrFail($data['user_id']);
            if (! $user->active) {
                throw new DomainException('O usuário selecionado está inativo.');
            }
            $phone = $this->phones->normalize($data['phone']);
            $existing = UserExternalIdentity::query()->where('channel', 'whatsapp')->where('active', true)
                ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('phone_normalized', $phone))->lockForUpdate()->first();
            if ($existing !== null) {
                throw new DomainException($existing->user_id === $user->id ? 'Este usuário já possui um WhatsApp ativo.' : 'Este telefone já está vinculado a outro usuário.');
            }
            try {
                $identity = UserExternalIdentity::query()->create([
                    'user_id' => $user->id, 'channel' => 'whatsapp', 'external_user_id' => $this->phones->providerIdentifier($phone),
                    'phone_normalized' => $phone, 'display_name' => filled($data['display_name'] ?? null) ? trim($data['display_name']) : $user->name,
                    'status' => 'approved', 'active' => true, 'respond_enabled' => (bool) ($data['respond_enabled'] ?? true),
                    'structured_commands_allowed' => (bool) ($data['structured_commands_allowed'] ?? true),
                    'menu_enabled' => (bool) ($data['menu_enabled'] ?? true), 'free_chat_allowed' => (bool) ($data['free_chat_allowed'] ?? false),
                    'voice_allowed' => (bool) ($data['voice_allowed'] ?? false), 'image_allowed' => (bool) ($data['image_allowed'] ?? false),
                    'document_allowed' => (bool) ($data['document_allowed'] ?? false), 'reports_allowed' => (bool) ($data['reports_allowed'] ?? false),
                    'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(), 'activated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Este usuário ou telefone já possui um acesso WhatsApp ativo.');
            }
            $this->events->record('identity_created', 'web', $actor, identityId: $identity->id, status: 'approved', metadata: ['target_user_id' => $user->id]);
            $this->events->record('identity_activated', 'web', $actor, identityId: $identity->id, status: 'approved', metadata: ['target_user_id' => $user->id]);

            return $identity;
        });
    }

    public function update(UserExternalIdentity $identity, array $data, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($identity, $data, $actor): UserExternalIdentity {
            $identity = UserExternalIdentity::query()->with('user')->lockForUpdate()->findOrFail($identity->id);
            $targetUserId = (int) ($data['user_id'] ?? $identity->user_id);
            if ($targetUserId !== $identity->user_id) {
                return $this->reassignUser($identity, $targetUserId, $data, $actor);
            }

            $oldStatus = $identity->status;
            $wasActive = $identity->active;
            $wasResponding = $identity->respond_enabled;
            $active = (bool) $data['active'] && $data['status'] === 'approved';
            if ($active && ! $identity->user?->active) {
                throw new DomainException('O usuário vinculado está inativo e não pode receber acesso ao agente.');
            }
            $attributes = [
                'display_name' => filled($data['display_name'] ?? null) ? trim($data['display_name']) : $identity->display_name,
                'status' => $data['status'], 'active' => $active,
                'respond_enabled' => (bool) ($data['respond_enabled'] ?? $identity->respond_enabled), 'menu_enabled' => $data['menu_enabled'],
                'structured_commands_allowed' => $data['structured_commands_allowed'], 'free_chat_allowed' => $data['free_chat_allowed'],
                'voice_allowed' => $data['voice_allowed'], 'image_allowed' => $data['image_allowed'],
                'document_allowed' => $data['document_allowed'], 'reports_allowed' => $data['reports_allowed'],
                'approved_by' => $data['status'] === 'approved' ? $actor->id : $identity->approved_by,
                'approved_at' => $data['status'] === 'approved' ? ($identity->approved_at ?? now()) : $identity->approved_at,
                'activated_at' => $active ? ($identity->activated_at ?? now()) : $identity->activated_at,
                'deactivated_at' => $active ? null : now(),
            ];
            try {
                $identity->update($attributes);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Este usuário ou telefone já possui um acesso WhatsApp ativo.');
            }
            if (! $active || ! $identity->respond_enabled) {
                $this->invalidatePending($identity, $active ? 'identity_response_disabled' : 'identity_deactivated');
            }
            $this->events->record('identity_updated', 'web', $actor, identityId: $identity->id, status: $data['status'], metadata: ['changed_fields' => array_keys($attributes), 'target_user_id' => $identity->user_id]);
            if ($wasActive !== $active) {
                $event = $active ? 'identity_activated' : 'identity_deactivated';
                $this->events->record($event, 'web', $actor, identityId: $identity->id, status: $data['status'], metadata: ['previous_status' => $oldStatus, 'target_user_id' => $identity->user_id]);
            } elseif ($wasResponding !== $identity->respond_enabled) {
                $event = $identity->respond_enabled ? 'identity_response_enabled' : 'identity_response_disabled';
                $this->events->record($event, 'web', $actor, identityId: $identity->id, status: $data['status'], metadata: ['target_user_id' => $identity->user_id]);
            }

            return $identity->refresh();
        });
    }

    public function replacePhone(UserExternalIdentity $identity, string $phone, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($identity, $phone, $actor): UserExternalIdentity {
            $identity = UserExternalIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $normalized = $this->phones->normalize($phone);
            if ($normalized === $identity->phone_normalized) {
                throw new DomainException('Informe um telefone diferente do atual.');
            }
            $identity->update(['active' => false, 'status' => 'inactive', 'deactivated_at' => now()]);
            $this->invalidatePending($identity, 'identity_phone_replaced');
            $this->events->record('identity_deactivated', 'web', $actor, identityId: $identity->id, status: 'inactive', metadata: ['reason' => 'phone_replaced', 'target_user_id' => $identity->user_id]);

            return $this->create([
                'user_id' => $identity->user_id, 'phone' => $normalized, 'display_name' => $identity->display_name,
                'respond_enabled' => $identity->respond_enabled, 'menu_enabled' => $identity->menu_enabled,
                'structured_commands_allowed' => $identity->structured_commands_allowed, 'free_chat_allowed' => $identity->free_chat_allowed,
                'voice_allowed' => $identity->voice_allowed, 'image_allowed' => $identity->image_allowed,
                'document_allowed' => $identity->document_allowed, 'reports_allowed' => $identity->reports_allowed,
            ], $actor);
        });
    }

    public function requestWelcome(UserExternalIdentity $identity, User $actor): UserExternalIdentity
    {
        if (! $identity->active || ! $identity->respond_enabled || $identity->user === null || ! $identity->user->active) {
            throw new DomainException('A identidade e o usuário precisam estar ativos e com respostas habilitadas.');
        }
        $identity->update(['welcome_status' => 'requested', 'welcome_requested_at' => now()]);
        if ($this->whatsApp instanceof FakeWhatsAppClient) {
            $this->whatsApp->sendText($identity->external_user_id, 'Olá, '.($identity->display_name ?: $identity->user->name).'. Seu acesso ao agente do ERP está ativo. Envie MENU para começar.');
            $identity->update(['welcome_status' => 'sent', 'welcome_sent_at' => now(), 'last_system_outbound_at' => now()]);
        }
        $this->events->record('identity_welcome_requested', 'web', $actor, identityId: $identity->id, status: $identity->fresh()->welcome_status);

        return $identity->refresh();
    }

    private function reassignUser(UserExternalIdentity $identity, int $targetUserId, array $data, User $actor): UserExternalIdentity
    {
        $target = User::query()->lockForUpdate()->findOrFail($targetUserId);
        if (! $target->active) {
            throw new DomainException('O novo usuário selecionado está inativo.');
        }
        $active = (bool) $data['active'] && $data['status'] === 'approved';
        if ($active && UserExternalIdentity::query()->where('channel', 'whatsapp')->where('active', true)->where('user_id', $target->id)->exists()) {
            throw new DomainException('O novo usuário já possui um WhatsApp ativo.');
        }

        $previousUserId = $identity->user_id;
        $identity->update(['active' => false, 'status' => 'inactive', 'deactivated_at' => now()]);
        $this->invalidatePending($identity, 'identity_user_changed');
        $this->events->record('identity_deactivated', 'web', $actor, identityId: $identity->id, status: 'inactive', metadata: ['reason' => 'user_changed', 'target_user_id' => $previousUserId]);

        try {
            $replacement = UserExternalIdentity::query()->create([
                'user_id' => $target->id, 'channel' => 'whatsapp', 'external_user_id' => $identity->external_user_id,
                'phone_normalized' => $identity->phone_normalized,
                'display_name' => filled($data['display_name'] ?? null) ? trim($data['display_name']) : $target->name,
                'status' => $data['status'], 'active' => $active,
                'respond_enabled' => (bool) ($data['respond_enabled'] ?? true), 'menu_enabled' => $data['menu_enabled'],
                'structured_commands_allowed' => $data['structured_commands_allowed'], 'free_chat_allowed' => $data['free_chat_allowed'],
                'voice_allowed' => $data['voice_allowed'], 'image_allowed' => $data['image_allowed'],
                'document_allowed' => $data['document_allowed'], 'reports_allowed' => $data['reports_allowed'],
                'created_by' => $actor->id, 'approved_by' => $data['status'] === 'approved' ? $actor->id : null,
                'approved_at' => $data['status'] === 'approved' ? now() : null,
                'activated_at' => $active ? now() : null, 'deactivated_at' => $active ? null : now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('Este usuário ou telefone já possui um acesso WhatsApp ativo.');
        }
        $this->events->record('identity_user_changed', 'web', $actor, identityId: $replacement->id, status: $replacement->status, metadata: ['previous_identity_id' => $identity->id, 'previous_user_id' => $previousUserId, 'target_user_id' => $target->id]);
        $this->events->record('identity_created', 'web', $actor, identityId: $replacement->id, status: $replacement->status, metadata: ['target_user_id' => $target->id]);
        if ($active) {
            $this->events->record('identity_activated', 'web', $actor, identityId: $replacement->id, status: $replacement->status, metadata: ['target_user_id' => $target->id]);
        }

        return $replacement;
    }

    private function invalidatePending(UserExternalIdentity $identity, string $reason): void
    {
        $identifiers = array_values(array_unique(array_filter([
            $identity->external_user_id,
            $identity->phone_normalized,
            ltrim((string) $identity->phone_normalized, '+'),
        ])));
        $conversationIds = AgentConversation::query()->where('user_id', $identity->user_id)->where('channel', 'whatsapp')
            ->whereIn('external_conversation_id', $identifiers)->pluck('id');
        PendingAgentAction::query()->whereIn('agent_conversation_id', $conversationIds)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'failure_reason' => $reason]);
    }
}
