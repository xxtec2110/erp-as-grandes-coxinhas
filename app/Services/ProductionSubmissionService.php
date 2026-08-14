<?php

namespace App\Services;

use App\Models\AgentAttachment;
use App\Models\ProductionSubmission;
use App\Models\ProductionUserPolicy;
use App\Models\User;
use App\Production\ProductionBoardInterpretation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductionSubmissionService
{
    public function __construct(private ProductionOrderService $orders, private AgentEventService $events) {}

    public function preview(ProductionUserPolicy $policy, AgentAttachment $attachment, ProductionBoardInterpretation $result, User $user, ?string $lateReason = null): ProductionSubmission
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        if (! $user->is_super_admin && ! $result->validFor($today)) {
            $this->deleteAttachment($attachment);
            $this->events->record('production_photo_rejected', 'whatsapp', $user, metadata: ['attachment_id' => $attachment->id, 'attachment_hash' => $attachment->content_hash, 'reason' => $result->errors[0] ?? 'invalid_date']);
            throw new DomainException('Não consegui validar a produção. Nenhuma informação foi registrada; envie uma nova foto completa.');
        }if (! $user->is_super_admin && CarbonImmutable::now(config('app.timezone'))->format('H:i:s') > $policy->cutoff_time) {
            throw new DomainException('O prazo da produção encerrou. Somente um administrador pode registrar retroativamente.');
        }if ($user->is_super_admin && $result->operationDate && ! $result->operationDate->isSameDay($today) && blank($lateReason)) {
            throw new DomainException('Informe o motivo do lançamento retroativo.');
        }

return ProductionSubmission::query()->updateOrCreate(['production_user_policy_id' => $policy->id, 'operation_date' => $result->operationDate?->toDateString() ?? $today->toDateString()], ['status' => 'awaiting_confirmation', 'agent_attachment_id' => $attachment->id, 'attachment_hash' => $attachment->content_hash, 'interpretation' => ['items' => $result->items, 'confidence' => $result->confidence], 'late_reason' => $lateReason, 'idempotency_key' => "production:{$policy->id}:".($result->operationDate?->toDateString() ?? $today->toDateString())]);
    }

    public function confirm(ProductionSubmission $submission, User $user): ProductionSubmission
    {
        return DB::transaction(function () use ($submission, $user) {
            $submission = ProductionSubmission::query()->with('policy')->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status === 'confirmed') {
                return $submission;
            }$items = array_map(fn ($i) => ['product_id' => $i['product_id'], 'produced_quantity' => (string) $i['quantity']], $submission->interpretation['items']);
            $order = $this->orders->planAndComplete(['location_id' => $submission->policy->location_id, 'production_date' => $submission->operation_date->toDateString(), 'idempotency_key' => "production-board:{$submission->id}", 'items' => $items, 'notes' => $submission->late_reason], $user, 'production_board');
            $submission->update(['status' => 'confirmed', 'production_order_id' => $order->id, 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'submitted_after_alert' => $submission->alert_sent]);
            $this->events->record('production_board_confirmed', 'whatsapp', $user, metadata: ['submission_id' => $submission->id, 'production_order_id' => $order->id]);

            return $submission->refresh();
        });
    }

    public function reject(ProductionSubmission $submission, User $user): ProductionSubmission
    {
        $attachment = $submission->agent_attachment_id ? AgentAttachment::query()->find($submission->agent_attachment_id) : null;
        if ($attachment) {
            $this->deleteAttachment($attachment);
        }$submission->update(['status' => 'rejected_by_user', 'file_deleted_at' => now(), 'interpretation' => null]);
        $this->events->record('production_board_rejected_by_user', 'whatsapp', $user, metadata: ['submission_id' => $submission->id, 'attachment_id' => $attachment?->id, 'attachment_hash' => $submission->attachment_hash, 'deleted_at' => now()->toIso8601String()]);

        return $submission->refresh();
    }

    private function deleteAttachment(AgentAttachment $attachment): void
    {
        if ($attachment->disk && $attachment->path) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }$attachment->update(['path' => null, 'processing_status' => 'deleted', 'metadata' => []]);
    }
}
