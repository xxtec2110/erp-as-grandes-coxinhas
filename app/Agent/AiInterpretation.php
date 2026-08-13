<?php

namespace App\Agent;

use DomainException;

readonly class AiInterpretation
{
    public function __construct(public string $intent, public ?string $tool, public string $confidence, public array $fields, public array $missingFields, public string $sourceType, public string $documentType, public string $summary, public array $usage = []) {}

    public static function fromArray(array $data, array $usage = []): self
    {
        foreach (['intent', 'confidence', 'fields', 'missing_fields', 'source_type', 'document_type', 'summary'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new DomainException('ai_response_schema_invalid');
            }
        }
        if (! is_string($data['intent']) || (! is_null($data['tool'] ?? null) && ! is_string($data['tool'])) || ! is_numeric($data['confidence']) || ! is_array($data['fields']) || ! is_array($data['missing_fields']) || ! is_string($data['source_type']) || ! is_string($data['document_type']) || ! is_string($data['summary'])) {
            throw new DomainException('ai_response_schema_invalid');
        }
        $confidence = (string) max(0, min(1, (float) $data['confidence']));

        return new self($data['intent'], $data['tool'] ?? null, $confidence, $data['fields'], array_values(array_filter($data['missing_fields'], 'is_string')), $data['source_type'], $data['document_type'], $data['summary'], $usage);
    }

    public function toArray(): array
    {
        return ['intent' => $this->intent, 'tool' => $this->tool, 'confidence' => $this->confidence, 'fields' => $this->fields, 'missing_fields' => $this->missingFields, 'source_type' => $this->sourceType, 'document_type' => $this->documentType, 'summary' => $this->summary];
    }
}
