<?php

namespace App\Services;

use App\Agent\PendingAgentActionService;
use App\Models\AgentAttachment;
use App\Models\PendingAgentAction;
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
    public function __construct(
        private ProductionOrderService $orders,
        private PendingAgentActionService $pending,
        private AgentEventService $events,
    ) {}

    public function preview(
        ProductionUserPolicy $policy,
        AgentAttachment $attachment,
        ProductionBoardInterpretation $result,
        User $user,
        ?string $lateReason = null,
        ?int $conversationId = null,
    ): ProductionSubmission {
        $today = CarbonImmutable::today(config('app.timezone'));
        if (! $user->is_super_admin && ! $result->validFor($today)) {
            throw new DomainException('Não consegui validar a produção. Nenhuma informação foi registrada; envie uma nova foto completa.');
        }
        if (! $user->is_super_admin && CarbonImmutable::now(config('app.timezone'))->format('H:i:s') > $policy->cutoff_time) {
            throw new DomainException('O prazo da produção encerrou. Somente um administrador pode registrar retroativamente.');
        }
        if ($user->is_super_admin && $result->operationDate && ! $result->operationDate->isSameDay($today) && blank($lateReason)) {
            throw new DomainException('Informe o motivo do lançamento retroativo.');
        }

        return DB::transaction(function () use ($policy, $attachment, $result, $user, $lateReason, $conversationId, $today): ProductionSubmission {
            $date = $result->operationDate?->toDateString() ?? $today->toDateString();
            $submission = ProductionSubmission::query()
                ->where('production_user_policy_id', $policy->id)
                ->whereDate('operation_date', $date)
                ->lockForUpdate()
                ->first();

            if ($submission?->status === 'confirmed') {
                throw new DomainException('A produção deste dia já foi confirmada.');
            }
            if ($submission?->status === 'awaiting_confirmation' && hash_equals((string) $submission->attachment_hash, (string) $attachment->content_hash)) {
                return $submission;
            }
            if ($submission?->pendingAction?->status === 'pending') {
                $this->pending->cancel($submission->pendingAction, $user);
            }
            if ($submission?->attachment && $submission->agent_attachment_id !== $attachment->id) {
                $this->deleteAttachment($submission->attachment);
            }

            $items = array_map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'produced_quantity' => (string) $item['quantity'],
            ], $result->items);
            $action = $this->pending->prepare(
                $user,
                'production.orders.complete_batch',
                ['location_id' => $policy->location_id, 'production_date' => $date, 'items' => $items, 'idempotency_key' => "production-board:{$policy->id}:{$date}"],
                [],
                "production-board:{$policy->id}:{$date}:{$attachment->content_hash}",
                $conversationId,
            );
            $action->update(['expires_at' => CarbonImmutable::parse("{$date} {$policy->cutoff_time}", config('app.timezone'))]);
            $values = [
                'status' => 'awaiting_confirmation',
                'agent_attachment_id' => $attachment->id,
                'pending_agent_action_id' => $action->id,
                'attachment_hash' => $attachment->content_hash,
                'interpretation' => [
                    'items' => $result->items,
                    'confidence' => $result->confidence,
                    'date_status' => $result->dateStatus,
                    'complete' => $result->complete,
                    'total' => $result->total(),
                ],
                'late_reason' => $lateReason,
                'idempotency_key' => "production:{$policy->id}:{$date}",
                'file_deleted_at' => null,
            ];

            if ($submission) {
                $submission->update($values);

                return $submission->refresh();
            }

            return ProductionSubmission::query()->create([
                'production_user_policy_id' => $policy->id,
                'operation_date' => $result->operationDate ?? $today,
                ...$values,
            ]);
        });
    }

    public function confirm(ProductionSubmission $submission, User $user): ProductionSubmission
    {
        return DB::transaction(function () use ($submission, $user) {
            $submission = ProductionSubmission::query()->with(['policy', 'attachment'])->lockForUpdate()->findOrFail($submission->id);
            if ($submission->policy->user_id !== $user->id) {
                throw new DomainException('Esta confirmação pertence a outro usuário.');
            }
            if ($submission->status === 'confirmed') {
                return $submission;
            }
            if ($submission->status !== 'awaiting_confirmation') {
                throw new DomainException('Esta prévia não está mais disponível para confirmação.');
            }
            if (! $user->is_super_admin) {
                $now = CarbonImmutable::now(config('app.timezone'));
                if (! $submission->operation_date->isSameDay($now) || $now->format('H:i:s') > $submission->policy->cutoff_time) {
                    throw new DomainException('O prazo da produção encerrou. Somente um administrador pode registrar retroativamente.');
                }
            }

            $action = PendingAgentAction::query()->lockForUpdate()->findOrFail($submission->pending_agent_action_id);
            if ($action->user_id !== $user->id || $action->status !== 'pending') {
                throw new DomainException('A confirmação pendente não está disponível.');
            }
            $action->update(['status' => 'confirmed', 'confirmed_at' => now()]);

            $items = collect($submission->interpretation['items'])
                ->filter(fn (array $item) => (int) $item['quantity'] > 0)
                ->map(fn (array $item) => ['product_id' => $item['product_id'], 'produced_quantity' => (string) $item['quantity']])
                ->values()
                ->all();
            $order = $items === [] ? null : $this->orders->planAndComplete([
                'location_id' => $submission->policy->location_id,
                'production_date' => $submission->operation_date->toDateString(),
                'idempotency_key' => "production-board:{$submission->id}",
                'items' => $items,
                'notes' => $submission->late_reason,
            ], $user, 'production_board');

            $action->update([
                'status' => 'executed',
                'executed_at' => now(),
                'result' => $order ? ['type' => $order::class, 'id' => $order->id] : ['type' => ProductionSubmission::class, 'id' => $submission->id],
            ]);
            $submission->update(['status' => 'confirmed', 'production_order_id' => $order?->id, 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'submitted_after_alert' => $submission->alert_sent]);
            if ($submission->attachment) {
                $submission->attachment->update(['retention_type' => 'official', 'processing_status' => 'confirmed']);
            }
            $this->events->record('production_board_confirmed', 'whatsapp', $user, metadata: ['submission_id' => $submission->id, 'production_order_id' => $order?->id]);

            return $submission->refresh();
        });
    }

    public function reject(ProductionSubmission $submission, User $user): ProductionSubmission
    {
        return DB::transaction(function () use ($submission, $user): ProductionSubmission {
            $submission = ProductionSubmission::query()->with('policy')->lockForUpdate()->findOrFail($submission->id);
            if ($submission->policy->user_id !== $user->id) {
                throw new DomainException('Esta confirmação pertence a outro usuário.');
            }
            if ($submission->status === 'rejected_by_user') {
                return $submission;
            }
            if ($submission->status !== 'awaiting_confirmation') {
                throw new DomainException('Esta prévia não pode mais ser rejeitada.');
            }

            $action = PendingAgentAction::query()->lockForUpdate()->find($submission->pending_agent_action_id);
            if ($action && $action->status !== 'executed') {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
            $attachment = $submission->agent_attachment_id ? AgentAttachment::query()->lockForUpdate()->find($submission->agent_attachment_id) : null;
            if ($attachment) {
                $this->deleteAttachment($attachment);
            }
            $submission->update(['status' => 'rejected_by_user', 'file_deleted_at' => now(), 'interpretation' => null]);
            $this->events->record('production_board_rejected_by_user', 'whatsapp', $user, metadata: ['submission_id' => $submission->id, 'attachment_id' => $attachment?->id, 'attachment_hash' => $submission->attachment_hash, 'deleted_at' => now()->toIso8601String()]);

            return $submission->refresh();
        });
    }

    public function discardInvalid(AgentAttachment $attachment, User $user, string $reason): void
    {
        $hash = $attachment->content_hash;
        $this->deleteAttachment($attachment);
        $this->events->record('production_photo_rejected', 'whatsapp', $user, metadata: [
            'attachment_id' => $attachment->id,
            'attachment_hash' => $hash,
            'reason' => $reason,
        ]);
    }

    public function markNotSubmitted(ProductionUserPolicy $policy, CarbonImmutable $date): ProductionSubmission
    {
        return DB::transaction(function () use ($policy, $date): ProductionSubmission {
            $submission = ProductionSubmission::query()->firstOrCreate([
                'production_user_policy_id' => $policy->id,
                'operation_date' => $date->startOfDay(),
            ], [
                'status' => 'production_not_submitted',
                'idempotency_key' => "production:{$policy->id}:{$date->toDateString()}",
            ]);
            $wasCreated = $submission->wasRecentlyCreated;
            $submission = ProductionSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status === 'confirmed') {
                return $submission;
            }
            if ($submission->status === 'production_not_submitted') {
                if ($wasCreated) {
                    $this->events->record('production_not_submitted', 'system', $policy->user, metadata: [
                        'policy_id' => $policy->id,
                        'location_id' => $policy->location_id,
                        'operation_date' => $date->toDateString(),
                    ]);
                }

                return $submission;
            }

            $action = PendingAgentAction::query()->lockForUpdate()->find($submission->pending_agent_action_id);
            if ($action && $action->status !== 'executed') {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            }
            $attachment = $submission->agent_attachment_id ? AgentAttachment::query()->lockForUpdate()->find($submission->agent_attachment_id) : null;
            if ($attachment) {
                $this->deleteAttachment($attachment);
            }
            $submission->update([
                'status' => 'production_not_submitted',
                'interpretation' => null,
                'file_deleted_at' => $attachment ? now() : $submission->file_deleted_at,
            ]);
            $this->events->record('production_not_submitted', 'system', $policy->user, metadata: [
                'policy_id' => $policy->id,
                'location_id' => $policy->location_id,
                'operation_date' => $date->toDateString(),
            ]);

            return $submission->refresh();
        });
    }

    private function deleteAttachment(AgentAttachment $attachment): void
    {
        if ($attachment->disk && $attachment->path) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }$attachment->update(['path' => null, 'processing_status' => 'deleted', 'metadata' => []]);
    }
}
