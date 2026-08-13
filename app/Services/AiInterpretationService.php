<?php

namespace App\Services;

use App\Agent\AgentMessage;
use App\Agent\AiInterpretation;
use App\Agent\AiProviderInterface;
use App\Models\AgentAttachment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Storage;

class AiInterpretationService
{
    public function __construct(private AiProviderInterface $provider, private AgentAttachmentService $attachments, private SupplierMatchService $suppliers, private IngredientMatchService $ingredients) {}

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
            throw new DomainException('ai_tool_not_allowed');
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
        } else {
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
        $fields['items'] = $this->ingredients->matchItems($fields['items']);

        return new AiInterpretation($result->intent, $result->tool, $result->confidence, $fields, $result->missingFields, $result->sourceType, $result->documentType, $result->summary, $result->usage);
    }

    private function promotePurpose(AgentAttachment $attachment, ?string $tool): void
    {
        $purpose = match ($tool) {
            'finance.payments.record', 'finance.payables.create' => 'finance',
            'purchases.documents.create' => 'purchase',
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
