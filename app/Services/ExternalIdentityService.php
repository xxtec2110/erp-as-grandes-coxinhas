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
                    'phone_normalized' => $phone, 'display_name' => $user->name, 'status' => 'approved', 'active' => true,
                    'structured_commands_allowed' => true, 'menu_enabled' => true, 'free_chat_allowed' => false,
                    'voice_allowed' => (bool) ($data['voice_allowed'] ?? false), 'image_allowed' => (bool) ($data['image_allowed'] ?? false),
                    'document_allowed' => (bool) ($data['document_allowed'] ?? false), 'reports_allowed' => (bool) ($data['reports_allowed'] ?? false),
                    'created_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(), 'activated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Este usuário ou telefone já possui um acesso WhatsApp ativo.');
            }
            $this->events->record('identity_activated', 'web', $actor, identityId: $identity->id, status: 'approved', metadata: ['target_user_id' => $user->id]);

            return $identity;
        });
    }

    public function update(UserExternalIdentity $identity, array $data, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($identity, $data, $actor): UserExternalIdentity {
            $identity = UserExternalIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $oldStatus = $identity->status;
            $active = $data['active'] && $data['status'] === 'approved';
            $identity->update([
                'status' => $data['status'], 'active' => $active, 'menu_enabled' => $data['menu_enabled'],
                'structured_commands_allowed' => $data['structured_commands_allowed'], 'free_chat_allowed' => $data['free_chat_allowed'],
                'voice_allowed' => $data['voice_allowed'], 'image_allowed' => $data['image_allowed'],
                'document_allowed' => $data['document_allowed'], 'reports_allowed' => $data['reports_allowed'],
                'approved_by' => $data['status'] === 'approved' ? $actor->id : $identity->approved_by,
                'approved_at' => $data['status'] === 'approved' ? ($identity->approved_at ?? now()) : $identity->approved_at,
                'activated_at' => $active ? ($identity->activated_at ?? now()) : $identity->activated_at,
                'deactivated_at' => $active ? null : now(),
            ]);
            if (! $active) {
                $this->invalidatePending($identity);
            }
            $event = $active ? 'identity_activated' : 'identity_deactivated';
            $this->events->record($event, 'web', $actor, identityId: $identity->id, status: $data['status'], metadata: ['previous_status' => $oldStatus, 'target_user_id' => $identity->user_id]);

            return $identity->refresh();
        });
    }

    public function replacePhone(UserExternalIdentity $identity, string $phone, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($identity, $phone, $actor): UserExternalIdentity {
            $identity = UserExternalIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $identity->update(['active' => false, 'status' => 'inactive', 'deactivated_at' => now()]);
            $this->invalidatePending($identity);

            return $this->create(['user_id' => $identity->user_id, 'phone' => $phone, 'voice_allowed' => $identity->voice_allowed, 'image_allowed' => $identity->image_allowed, 'document_allowed' => $identity->document_allowed, 'reports_allowed' => $identity->reports_allowed], $actor);
        });
    }

    public function requestWelcome(UserExternalIdentity $identity, User $actor): UserExternalIdentity
    {
        if (! $identity->active || $identity->user === null) {
            throw new DomainException('A identidade precisa estar ativa para receber boas-vindas.');
        }
        $identity->update(['welcome_status' => 'requested', 'welcome_requested_at' => now()]);
        if ($this->whatsApp instanceof FakeWhatsAppClient) {
            $this->whatsApp->sendText($identity->external_user_id, 'Olá, '.$identity->user->name.'. Seu acesso ao agente do ERP está ativo. Envie MENU para começar.');
            $identity->update(['welcome_status' => 'sent', 'welcome_sent_at' => now(), 'last_system_outbound_at' => now()]);
        }
        $this->events->record('identity_welcome_requested', 'web', $actor, identityId: $identity->id, status: $identity->fresh()->welcome_status);

        return $identity->refresh();
    }

    private function invalidatePending(UserExternalIdentity $identity): void
    {
        $conversationIds = AgentConversation::query()->where('user_id', $identity->user_id)->where('channel', 'whatsapp')->pluck('id');
        PendingAgentAction::query()->whereIn('agent_conversation_id', $conversationIds)->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'failure_reason' => 'identity_deactivated']);
    }
}
