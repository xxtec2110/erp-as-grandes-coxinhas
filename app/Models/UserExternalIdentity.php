<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserExternalIdentity extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'respond_enabled' => 'boolean', 'menu_enabled' => 'boolean', 'structured_commands_allowed' => 'boolean', 'free_chat_allowed' => 'boolean', 'voice_allowed' => 'boolean', 'image_allowed' => 'boolean', 'document_allowed' => 'boolean', 'reports_allowed' => 'boolean', 'approved_at' => 'datetime', 'activated_at' => 'datetime', 'deactivated_at' => 'datetime', 'welcome_requested_at' => 'datetime', 'welcome_sent_at' => 'datetime', 'last_authorized_inbound_at' => 'datetime', 'last_system_outbound_at' => 'datetime', 'last_contact_at' => 'datetime', 'metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
