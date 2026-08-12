<?php

namespace App\Services;

use App\Models\PurchaseDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePurchaseDocumentService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit, private AgentAttachmentService $attachments) {}

    public function create(array $data, User $user, string $source = 'web'): PurchaseDocument
    {
        $this->auth->authorize($user, 'purchases.create', (int) $data['location_id']);
        if (isset($data['agent_attachment_id'])) {
            $this->attachments->authorizeLink((int) $data['agent_attachment_id'], 'purchase', (int) $data['location_id'], $user);
        }

        return DB::transaction(function () use ($data, $user, $source) {
            $existing = PurchaseDocument::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }$items = $data['items'] ?? [];
            unset($data['items']);
            $document = PurchaseDocument::query()->create([...$data, 'source' => $source, 'created_by' => $user->id]);
            foreach ($items as $item) {
                $document->items()->create($item);
            }$this->audit->record('purchase_document.created', $document, $user, $document->toArray(), null, $source, $data['idempotency_key']);

            return $document->load('items');
        });
    }
}
