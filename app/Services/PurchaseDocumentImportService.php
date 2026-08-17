<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Models\AgentAttachment;
use App\Models\Ingredient;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentImport;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseDocumentImportService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AgentAttachmentService $attachments,
        private AiInterpretationService $ai,
        private PurchaseDocumentInterpretationService $interpretations,
        private SupplierIngredientMappingService $mappings,
        private CreatePurchaseDocumentService $documents,
        private PurchaseReceiptService $receipts,
        private UnitConversionService $units,
        private AgentCostService $costs,
        private PurchaseDocumentIdentityService $identities,
    ) {}

    /** @param array<int, UploadedFile> $files */
    public function upload(array $files, int $locationId, User $user): PurchaseDocumentImport
    {
        $this->authorization->authorize($user, 'purchases.import', $locationId);
        $stored = collect($files)->map(fn (UploadedFile $file) => $this->attachments->store($file, 'purchase_import', $locationId, 'temporary', $user));
        $hasRepeatedPage = $stored->pluck('id')->duplicates()->isNotEmpty();
        $stored = $stored->unique('id')->values();
        $key = 'purchase-import:'.hash('sha256', $user->id.'|'.$locationId.'|'.$stored->pluck('content_hash')->sort()->implode('|'));
        $existing = PurchaseDocumentImport::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing->load(['attachments', 'items.ingredient', 'supplier', 'location']);
        }

        $import = PurchaseDocumentImport::query()->create(['user_id' => $user->id, 'location_id' => $locationId, 'status' => 'interpreting', 'idempotency_key' => $key, 'expires_at' => now()->addDays(7)]);
        foreach ($stored->values() as $index => $attachment) {
            $import->attachments()->attach($attachment->id, ['page_order' => $index + 1]);
        }
        $type = $stored->every(fn (AgentAttachment $attachment) => str_starts_with((string) $attachment->mime_type, 'image/')) ? 'image' : 'document';
        try {
            $started = hrtime(true);
            $ai = $this->ai->interpret(new AgentMessage('web', 'user-'.$user->id, (string) Str::uuid(), null, $type, $stored->pluck('id')->all()), ['purchases.documents.create'], $user);
            if ($ai === null) {
                throw new DomainException('Não foi possível interpretar o documento.');
            }
            if (! ($ai->usage['cached'] ?? false) && (string) config('ai.provider') === 'openai') {
                $this->costs->record('openai', 'ai_vision', $key.':vision', $user, [...$ai->usage, 'location_id' => $locationId, 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'operation_type' => 'purchase_document_interpretation', 'operation_id' => (string) $import->id]);
            }
            $normalized = $this->interpretations->normalize($ai);
            if ($hasRepeatedPage) {
                $normalized['warnings'][] = 'Uma foto repetida foi ignorada antes da interpretação.';
                $normalized['warnings'] = array_values(array_unique($normalized['warnings']));
            }
            $this->persistInterpretation($import, $normalized);
        } catch (\Throwable) {
            $import->update(['status' => 'failed', 'warnings' => ['Não foi possível interpretar automaticamente. O arquivo foi preservado para nova revisão.']]);
        }

        return $import->refresh()->load(['attachments', 'items.ingredient.currentPrice', 'supplier', 'location']);
    }

    public function revise(PurchaseDocumentImport $import, array $data, User $user): PurchaseDocumentImport
    {
        $this->authorizeImport($import, $user, 'purchases.approve');
        $this->assertEditable($import);

        return DB::transaction(function () use ($import, $data): PurchaseDocumentImport {
            $import = PurchaseDocumentImport::query()->lockForUpdate()->findOrFail($import->id);
            $items = $data['items'];
            $received = (bool) ($data['received'] ?? false);
            $receivedDate = $data['received_date'] ?? null;
            unset($data['items'], $data['received'], $data['received_date']);
            $warnings = $this->reconcile($data, $items);
            $import->update([...$data, 'status' => $warnings === [] ? 'ready_for_confirmation' : 'needs_review', 'warnings' => $warnings, 'missing_fields' => [], 'ambiguous_fields' => [], 'interpretation' => [...($import->interpretation ?? []), 'received' => $received, 'received_date' => $receivedDate]]);
            foreach ($items as $id => $itemData) {
                $item = $import->items()->whereKey($id)->lockForUpdate()->firstOrFail();
                $ingredient = Ingredient::query()->findOrFail($itemData['ingredient_id']);
                $normalizationQuantity = (string) $itemData['quantity'];
                $normalizationUnit = (string) $itemData['unit'];
                if (isset($itemData['package_quantity'], $itemData['package_size'], $itemData['package_unit'])) {
                    $normalizationQuantity = (string) BigDecimal::of((string) $itemData['package_quantity'])->multipliedBy((string) $itemData['package_size']);
                    $normalizationUnit = (string) $itemData['package_unit'];
                }
                $normalized = $this->units->normalize($normalizationQuantity, $normalizationUnit, $ingredient->base_unit);
                unset($itemData['save_mapping']);
                $item->update([
                    ...$itemData,
                    'mapping_status' => 'administrator_confirmed',
                    'normalized_quantity' => $normalized,
                    'normalized_unit' => $ingredient->base_unit,
                    'normalized_unit_cost' => $this->units->calculateBaseUnitCost((string) $itemData['net_amount'], $normalized),
                ]);
            }

            return $import->refresh()->load(['attachments', 'items.ingredient.currentPrice', 'supplier', 'location']);
        });
    }

    public function confirm(PurchaseDocumentImport $import, User $user): PurchaseDocument
    {
        $this->authorizeImport($import, $user, 'purchases.approve');
        if ($import->confirmed_purchase_document_id !== null) {
            return PurchaseDocument::query()->findOrFail($import->confirmed_purchase_document_id);
        }
        $this->assertEditable($import);

        return DB::transaction(function () use ($import, $user): PurchaseDocument {
            $import = PurchaseDocumentImport::query()->with(['attachments', 'items.ingredient'])->lockForUpdate()->findOrFail($import->id);
            if ($import->confirmed_purchase_document_id !== null) {
                return PurchaseDocument::query()->findOrFail($import->confirmed_purchase_document_id);
            }
            if ($import->status !== 'ready_for_confirmation' || $import->supplier_id === null || $import->items->isEmpty() || $import->items->contains(fn ($item) => $item->ingredient_id === null)) {
                throw new DomainException('A revisão precisa estar completa antes da confirmação.');
            }
            $identity = $this->identities->hash($import->toArray());
            if ($identity !== null && ($duplicate = PurchaseDocument::query()->where('identity_hash', $identity)->first()) !== null) {
                $import->update(['status' => 'duplicate', 'confirmed_purchase_document_id' => $duplicate->id]);

                return $duplicate;
            }
            foreach ($import->attachments as $attachment) {
                $metadata = $attachment->metadata ?? [];
                $metadata['purpose'] = 'purchase';
                $attachment->update(['metadata' => $metadata, 'retention_type' => 'official']);
            }
            $document = $this->documents->create([
                'supplier_id' => $import->supplier_id,
                'document_type' => match ($import->document_type) {
                    'purchase_invoice' => 'invoice', 'purchase_receipt' => 'receipt', 'purchase_order' => 'order', 'quotation' => 'quote'
                },
                'document_number' => $import->document_number,
                'series' => $import->series,
                'access_key' => $import->access_key,
                'issue_date' => $import->issue_date->toDateString(),
                'total_amount' => $import->total_amount,
                'gross_amount' => $import->gross_amount,
                'discount_amount' => $import->discount_amount,
                'freight_amount' => $import->freight_amount,
                'other_charges_amount' => $import->other_charges_amount,
                'net_amount' => $import->total_amount,
                'currency' => $import->currency,
                'source_type' => $import->document_type === 'quotation' ? 'quote' : 'purchase',
                'location_id' => $import->location_id,
                'agent_attachment_id' => $import->attachments->first()?->id,
                'idempotency_key' => 'purchase-import:'.$import->id,
                'identity_hash' => $identity,
                'items' => $import->items->map(fn ($item) => $item->only(['ingredient_id', 'external_code', 'description', 'quantity', 'unit', 'package_quantity', 'package_size', 'package_unit', 'unit_price_original', 'package_price', 'gross_amount', 'discount_amount', 'freight_amount', 'other_charges_amount', 'net_amount']))->all(),
            ], $user, 'vision_import');

            foreach ($import->items as $item) {
                $this->mappings->confirm($import->supplier_id, $item->ingredient, $item->external_code, $item->description, $user);
            }
            $received = (bool) data_get($import->interpretation, 'received', false);
            if ($received && $import->document_type !== 'quotation') {
                $receivedDate = (string) (data_get($import->interpretation, 'received_date') ?: $import->issue_date->toDateString());
                $this->receipts->receive($document, $receivedDate, $user, 'vision_import');
            }
            $import->update(['status' => 'confirmed', 'confirmed_purchase_document_id' => $document->id, 'confirmed_at' => now()]);

            return $document;
        });
    }

    public function cancel(PurchaseDocumentImport $import, User $user): PurchaseDocumentImport
    {
        $this->authorizeImport($import, $user, 'purchases.approve');
        if (! in_array($import->status, ['confirmed', 'duplicate'], true)) {
            $import->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        return $import->refresh();
    }

    private function persistInterpretation(PurchaseDocumentImport $import, array $data): void
    {
        DB::transaction(function () use ($import, $data): void {
            $status = $this->status($data);
            $identity = $this->identities->hash($data);
            if ($identity !== null && PurchaseDocument::query()->where('identity_hash', $identity)->exists()) {
                $status = 'duplicate';
            }
            $items = $data['items'];
            unset($data['items'], $data['raw']);
            $import->update([...$data, 'status' => $status, 'identity_hash' => $identity, 'interpretation' => $data + ['raw_classification' => $data['document_type']]]);
            foreach ($items as $item) {
                $import->items()->create($item);
            }
        });
    }

    private function status(array $data): string
    {
        if (! in_array($data['document_type'], ['purchase_invoice', 'purchase_receipt', 'purchase_order', 'quotation'], true)) {
            return 'needs_review';
        }
        if ($data['supplier_id'] === null || $data['missing_fields'] !== [] || $data['ambiguous_fields'] !== [] || collect($data['items'])->contains(fn (array $item) => $item['ingredient_id'] === null)) {
            return 'needs_review';
        }

        return 'ready_for_confirmation';
    }

    private function reconcile(array $header, array $items): array
    {
        $lineTotal = collect($items)->reduce(fn (BigDecimal $sum, array $item) => $sum->plus((string) $item['net_amount']), BigDecimal::zero());
        $headerTotal = BigDecimal::of((string) $header['total_amount']);
        $warnings = [];
        if ($lineTotal->minus($headerTotal)->abs()->isGreaterThan('0.02')) {
            $warnings[] = 'A soma líquida dos itens não confere com o total do documento.';
        }
        $reconciled = BigDecimal::of((string) ($header['gross_amount'] ?? $header['total_amount']))->minus((string) $header['discount_amount'])->plus((string) $header['freight_amount'])->plus((string) $header['other_charges_amount']);
        if ($reconciled->minus($headerTotal)->abs()->isGreaterThan('0.02')) {
            $warnings[] = 'O total não confere com subtotal, desconto, frete e acréscimos.';
        }

        return $warnings;
    }

    private function authorizeImport(PurchaseDocumentImport $import, User $user, string $permission): void
    {
        $this->authorization->authorize($user, $permission, $import->location_id);
        if ($import->user_id !== $user->id && ! $user->is_super_admin) {
            abort(403);
        }
    }

    private function assertEditable(PurchaseDocumentImport $import): void
    {
        if ($import->expires_at?->isPast()) {
            throw new DomainException('Esta revisão expirou. Envie o documento novamente.');
        }
        if (in_array($import->status, ['confirmed', 'cancelled', 'duplicate'], true)) {
            throw new DomainException('Esta revisão não pode mais ser alterada.');
        }
    }
}
