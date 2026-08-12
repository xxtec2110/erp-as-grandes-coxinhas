<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserExternalIdentity;
use DomainException;
use Illuminate\Support\Facades\DB;

class ExternalIdentityService
{
    public function __construct(private UserAccessService $access, private AgentEventService $events) {}

    public function update(UserExternalIdentity $identity, array $data, User $actor): UserExternalIdentity
    {
        return DB::transaction(function () use ($identity, $data, $actor): UserExternalIdentity {
            $status = $data['status'];
            if ($status === 'approved' && empty($data['user_id'])) {
                throw new DomainException('Uma identidade aprovada deve estar vinculada a um usuário ERP.');
            }
            $oldStatus = $identity->status;
            $identity->update(['display_name' => $data['display_name'] ?? null, 'user_id' => $data['user_id'] ?? null, 'status' => $status, 'active' => $data['active'] && $status === 'approved', 'menu_enabled' => $data['menu_enabled'], 'structured_commands_allowed' => $data['structured_commands_allowed'], 'free_chat_allowed' => $data['free_chat_allowed'], 'voice_allowed' => $data['voice_allowed'], 'image_allowed' => $data['image_allowed'], 'document_allowed' => $data['document_allowed'], 'reports_allowed' => $data['reports_allowed'], 'approved_by' => $status === 'approved' ? $actor->id : $identity->approved_by, 'approved_at' => $status === 'approved' ? ($identity->approved_at ?? now()) : $identity->approved_at]);
            if ($identity->user_id !== null) {
                $this->access->update(User::query()->findOrFail($identity->user_id), $data, $actor, 'agent_identity_admin');
            }
            $event = match ($status) {
                'approved' => 'identity_approved', 'rejected' => 'identity_rejected', 'blocked' => 'identity_blocked', default => $oldStatus !== $status ? 'identity_status_changed' : 'identity_updated'
            };
            $this->events->record($event, 'web', $actor, identityId: $identity->id, status: $status, metadata: ['previous_status' => $oldStatus, 'target_user_id' => $identity->user_id]);

            return $identity->refresh();
        });
    }
}
