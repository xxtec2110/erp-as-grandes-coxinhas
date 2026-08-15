<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['operation_date' => 'date', 'interpretation' => 'array', 'briefing_sent' => 'boolean', 'alert_sent' => 'boolean', 'submitted_after_alert' => 'boolean', 'briefing_sent_at' => 'datetime', 'alert_sent_at' => 'datetime', 'confirmed_at' => 'datetime', 'file_deleted_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ProductionUserPolicy::class, 'production_user_policy_id');
    }

    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAgentAction::class, 'pending_agent_action_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AgentAttachment::class, 'agent_attachment_id');
    }
}
