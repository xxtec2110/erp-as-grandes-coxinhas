<?php

namespace App\Agent;

class AgentInterpretationSchema
{
    public static function definition(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['intent', 'tool', 'confidence', 'fields', 'missing_fields', 'source_type', 'document_type', 'summary'], 'properties' => [
            'intent' => ['type' => 'string'], 'tool' => ['type' => ['string', 'null']], 'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'fields' => ['type' => 'object', 'additionalProperties' => true], 'missing_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
            'source_type' => ['type' => 'string', 'enum' => ['text', 'image', 'document']],
            'document_type' => ['type' => 'string', 'enum' => ['none', 'payment_receipt', 'boleto', 'purchase_document', 'generic_financial', 'production_board', 'unknown']],
            'summary' => ['type' => 'string'],
        ]];
    }
}
