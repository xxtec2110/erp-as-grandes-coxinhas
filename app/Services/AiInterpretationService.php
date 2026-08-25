<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Agent\AgentToolRegistry;
use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Models\AgentAttachment;
use App\Models\FinancialAccount;
use App\Models\LossReason;
use App\Models\Payable;
use App\Models\PurchaseDocument;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

class AiInterpretationService
{
    public function __construct(private AiProviderInterface $provider, private AgentAttachmentService $attachments, private SupplierMatchService $suppliers, private IngredientMatchService $ingredients, private IngredientSemanticResolver $ingredientResolver, private ProductMatchService $products, private AgentToolRegistry $registry) {}

    public function interpret(AgentMessage $message, array $availableTools, User $user, array $conversationContext = []): ?AiInterpretation
    {
        $authorized = $this->authorizedAttachments($message, $user);
        $cacheKey = $this->cacheKey($authorized, $availableTools);
        if ($cacheKey !== null && ($cached = $this->cached($authorized[0], $cacheKey)) !== null) {
            return AiInterpretation::fromArray($cached, ['cached' => true]);
        }
        $context = ['conversation' => $this->safeContext($conversationContext), 'attachments' => array_map(fn (AgentAttachment $attachment) => [
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_name ?? 'documento',
            'data' => base64_encode(Storage::disk($attachment->disk)->get($attachment->path)),
        ], $authorized)];
        $result = $this->provider->interpret($message, $availableTools, $context);
        if ($result === null) {
            return null;
        }
        if ($result->tool !== null && ! in_array($result->tool, $availableTools, true)) {
            throw new DomainException($this->registry->get($result->tool) === null ? 'ai_tool_unknown' : 'ai_tool_not_allowed');
        }
        if ($result->tool === 'finance.payables.create' && isset($result->fields['amount']) && ! isset($result->fields['expected_amount'])) {
            $fields = $result->fields;
            $fields['expected_amount'] = $fields['amount'];
            unset($fields['amount']);
            $result = new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, $result->missingFields, $result->sourceType, $result->documentType, $result->summary, $result->usage);
        }
        if (count($authorized) === 1) {
            $this->promotePurpose($authorized[0], $result->tool);
            $cacheKey = $this->cacheKey($authorized, $availableTools);
        }
        if (count($authorized) === 1 && in_array($result->tool, ['finance.payments.record', 'finance.payables.create', 'purchases.documents.create'], true)) {
            $result = new AiInterpretation($result->intent, $result->tool, $result->confidence, [...$result->fields, 'agent_attachment_id' => $authorized[0]->id], $result->missingFields, $result->sourceType, $result->documentType, $result->summary, $result->usage);
        }
        $result = $this->matchSupplier($result);
        $result = $this->matchIngredients($result);
        $result = $this->matchProducts($result);
        $result = $this->matchSingularEntities($result);
        if ($cacheKey !== null) {
            $this->cache($authorized[0], $cacheKey, $result);
        }

        return $result;
    }

    private function authorizedAttachments(AgentMessage $message, User $user): array
    {
        if (! in_array($message->messageType, ['image', 'document'], true)) {
            return [];
        }
        if ($message->attachments === []) {
            throw new DomainException('media_attachment_required');
        }

        return array_map(function ($reference) use ($user): AgentAttachment {
            $id = is_array($reference) ? ($reference['id'] ?? null) : $reference;
            $attachment = AgentAttachment::query()->findOrFail($id);
            $purpose = (string) ($attachment->metadata['purpose'] ?? 'agent');
            if ($attachment->created_by !== $user->id || $attachment->location_id === null) {
                throw new DomainException('media_attachment_not_authorized');
            }

            return $this->attachments->authorizeLink($attachment->id, $purpose, $attachment->location_id, $user);
        }, $message->attachments);
    }

    private function matchSupplier(AiInterpretation $result): AiInterpretation
    {
        $name = $result->fields['supplier_name'] ?? $result->fields['beneficiary'] ?? null;
        $document = $result->fields['supplier_document_number'] ?? $result->fields['supplier_tax_id'] ?? $result->fields['cnpj'] ?? null;
        if (! is_string($name) && ! is_string($document)) {
            return $result;
        }
        $match = $this->suppliers->match(is_string($name) ? $name : null, is_string($document) ? $document : null);
        $fields = $result->fields;
        $missing = $result->missingFields;
        if (in_array($match['status'], ['fiscal_exact', 'name_exact'], true)) {
            $fields['supplier_id'] = $match['supplier_id'];
        } elseif (in_array($result->tool, ['purchases.documents.create', 'catalog.suppliers.update', 'catalog.ingredient_prices.add'], true)) {
            $fields['_supplier_match'] = ['status' => $match['status'], 'candidates' => $match['candidates']];
            $missing[] = 'supplier_id';
        }

        return new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, array_values(array_unique($missing)), $result->sourceType, $result->documentType, $result->summary, $result->usage);
    }

    private function matchIngredients(AiInterpretation $result): AiInterpretation
    {
        if (! isset($result->fields['items']) || ! is_array($result->fields['items'])) {
            return $result;
        }
        $fields = $result->fields;
        $fields['items'] = $this->ingredients->matchItems($fields['items'], isset($fields['supplier_id']) ? (int) $fields['supplier_id'] : null);
        $missing = $result->missingFields;
        if ($result->tool === 'purchases.documents.create') {
            foreach ($fields['items'] as $index => $item) {
                if (! isset($item['ingredient_id'])) {
                    $missing[] = 'items.'.($index + 1).'.ingredient_id';
                }
            }
        }

        return new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, array_values(array_unique($missing)), $result->sourceType, $result->documentType, $result->summary, $result->usage);
    }

    private function matchProducts(AiInterpretation $result): AiInterpretation
    {
        if (! isset($result->fields['items']) || ! is_array($result->fields['items']) || ! str_starts_with((string) $result->tool, 'production.orders.')) {
            return $result;
        }
        $fields = $result->fields;
        $fields['items'] = $this->products->matchItems($fields['items']);
        $missing = $result->missingFields;
        if (collect($fields['items'])->contains(fn ($item) => ! isset($item['product_id']))) {
            $missing[] = 'product_id';
        }

        return new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, array_values(array_unique($missing)), $result->sourceType, $result->documentType, $result->summary, $result->usage);
    }

    private function matchSingularEntities(AiInterpretation $result): AiInterpretation
    {
        $fields = $result->fields;
        $missing = $result->missingFields;
        $tool = (string) $result->tool;

        if (! isset($fields['product_id']) && is_string($fields['product_name'] ?? null) && in_array($tool, [
            'catalog.products.update', 'catalog.products.update_price', 'catalog.product_aliases.create',
            'losses.record', 'losses.query', 'transfers.create', 'transfers.complete',
        ], true)) {
            $matched = $this->products->resolveExactItems([['product_name' => $fields['product_name']]])[0];
            if (isset($matched['product_id'])) {
                $fields['product_id'] = $matched['product_id'];
            } else {
                $fields['_product_match'] = $this->products->matchItems([['product_name' => $fields['product_name']]])[0]['_product_match'] ?? ['status' => 'not_found', 'candidates' => []];
                $missing[] = 'product_id';
            }
        }

        if (! isset($fields['ingredient_id']) && is_string($fields['ingredient_name'] ?? null) && in_array($tool, [
            'catalog.ingredients.update', 'catalog.ingredient_prices.add', 'costs.ingredients.current',
            'costs.ingredients.history', 'costs.ingredients.compare_suppliers',
        ], true)) {
            $matched = $this->ingredientResolver->resolve($fields['ingredient_name']);
            if ($matched['status'] === 'resolved') {
                $fields['ingredient_id'] = $matched['ingredient_id'];
            } else {
                $fields['_ingredient_match'] = ['status' => $matched['status'], 'candidates' => $matched['candidates'] ?? []];
                $missing[] = 'ingredient_id';
            }
        }

        if ($tool === 'purchases.receipts.receive' && ! isset($fields['document_id']) && filled($fields['document_number'] ?? null)) {
            $matches = PurchaseDocument::query()->where('document_number', trim((string) $fields['document_number']))->get();
            if ($matches->count() === 1) {
                $fields['document_id'] = $matches->sole()->id;
            } else {
                $fields['_document_match'] = ['status' => $matches->isEmpty() ? 'not_found' : 'ambiguous', 'candidates' => $matches->take(5)->pluck('id')->all()];
                $missing[] = 'document_id';
            }
        }

        if ($tool === 'finance.payments.record' && ! isset($fields['payable_id']) && filled($fields['payable_description'] ?? null)) {
            $name = mb_strtolower(trim((string) $fields['payable_description']));
            $matches = Payable::query()->whereNotIn('status', ['paid', 'cancelled'])->get()->filter(fn (Payable $payable) => mb_strtolower(trim($payable->description)) === $name);
            if ($matches->count() === 1) {
                $fields['payable_id'] = $matches->first()->id;
            } else {
                $fields['_payable_match'] = ['status' => $matches->isEmpty() ? 'not_found' : 'ambiguous', 'candidates' => $matches->take(5)->pluck('id')->all()];
                $missing[] = 'payable_id';
            }
        }

        if ($tool === 'finance.payments.record' && ! isset($fields['financial_account_id']) && filled($fields['financial_account_name'] ?? null)) {
            $name = mb_strtolower(trim((string) $fields['financial_account_name']));
            $matches = FinancialAccount::query()->where('active', true)->get()->filter(fn (FinancialAccount $account) => mb_strtolower(trim($account->name)) === $name);
            if ($matches->count() === 1) {
                $fields['financial_account_id'] = $matches->first()->id;
            } else {
                $fields['_financial_account_match'] = ['status' => $matches->isEmpty() ? 'not_found' : 'ambiguous', 'candidates' => $matches->take(5)->pluck('id')->all()];
                $missing[] = 'financial_account_id';
            }
        }

        if ($tool === 'losses.record' && ! isset($fields['loss_reason_id']) && filled($fields['loss_reason_name'] ?? null)) {
            $name = mb_strtolower(trim((string) $fields['loss_reason_name']));
            $matches = LossReason::query()->where('active', true)->get()->filter(fn (LossReason $reason) => mb_strtolower(trim($reason->name)) === $name);
            if ($matches->count() === 1) {
                $fields['loss_reason_id'] = $matches->first()->id;
            } else {
                $fields['_loss_reason_match'] = ['status' => $matches->isEmpty() ? 'not_found' : 'ambiguous', 'candidates' => $matches->take(5)->pluck('id')->all()];
                $missing[] = 'loss_reason_id';
            }
        }

        return new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, array_values(array_unique($missing)), $result->sourceType, $result->documentType, $result->summary, $result->usage);
    }

    private function promotePurpose(AgentAttachment $attachment, ?string $tool): void
    {
        if (($attachment->metadata['purpose'] ?? null) === 'purchase_import' && $tool === 'purchases.documents.create') {
            return;
        }
        $purpose = match ($tool) {
            'finance.payments.record', 'finance.payables.create' => 'finance',
            'purchases.documents.create' => 'purchase',
            'production.orders.plan', 'production.orders.complete_batch' => 'production',
            default => null,
        };
        if ($purpose === null) {
            return;
        }
        $metadata = $attachment->metadata ?? [];
        $metadata['purpose'] = $purpose;
        $attachment->update(['metadata' => $metadata]);
    }

    private function cacheKey(array $attachments, array $tools): ?string
    {
        if (count($attachments) !== 1) {
            return null;
        }

        return hash('sha256', implode('|', [$attachments[0]->content_hash, $attachments[0]->metadata['purpose'] ?? 'agent', config('ai.provider'), $this->modelFor($attachments[0]), implode(',', $tools)]));
    }

    private function modelFor(AgentAttachment $attachment): string
    {
        return (string) (str_starts_with($attachment->mime_type, 'image/') ? config('ai.models.vision') : config('ai.models.document'));
    }

    private function cached(AgentAttachment $attachment, string $key): ?array
    {
        $value = data_get($attachment->metadata, 'ai_interpretations.'.$key);

        return is_array($value) ? $value : null;
    }

    private function cache(AgentAttachment $attachment, string $key, AiInterpretation $result): void
    {
        $metadata = $attachment->metadata ?? [];
        $metadata['ai_interpretations'][$key] = $result->toArray();
        $attachment->update(['metadata' => $metadata, 'processing_status' => 'interpreted']);
    }

    private function safeContext(array $context): array
    {
        return array_intersect_key($context, array_flip(['active_menu', 'last_tool']));
    }
}
