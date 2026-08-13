<?php

namespace App\Agent;

use App\Models\Product;

class FakeAiProvider implements AiProviderInterface
{
    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?AiInterpretation
    {
        if (isset($message->metadata['fake_intent'])) {
            return $this->make($message->metadata['fake_intent'], $message->messageType);
        }
        if (mb_strtoupper(trim($message->text ?? '')) === 'CRIAR CONTA TESTE') {
            return $this->make(['tool' => 'finance.payables.create', 'arguments' => ['description' => 'Conta criada no simulador', 'expected_amount' => '10', 'competency_date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'recurring' => false, 'recurrence_rule' => null, 'supplier_id' => null, 'cost_center_id' => null, 'finance_category_id' => null, 'notes' => 'Simulação local']], $message->messageType);
        }
        if (preg_match('/^PRODUZIMOS\s+([0-9]+(?:[.,][0-9]+)?)\s+(.+)$/ui', trim($message->text ?? ''), $matches) === 1) {
            $name = mb_strtolower(trim($matches[2]));
            $product = Product::query()->where('active', true)->get()->first(
                fn (Product $product) => mb_strtolower($product->name) === $name
            );
            if ($product !== null) {
                return $this->make(['tool' => 'production.plan', 'arguments' => [
                    'product_id' => $product->id,
                    'planned_quantity' => str_replace(',', '.', $matches[1]),
                    'operation_date' => now()->toDateString(),
                ]], $message->messageType);
            }
        }

        return null;
    }

    private function make(array $intent, string $source): AiInterpretation
    {
        return AiInterpretation::fromArray([
            'intent' => $intent['intent'] ?? $intent['tool'] ?? 'unknown',
            'tool' => $intent['tool'] ?? null,
            'confidence' => $intent['confidence'] ?? 1,
            'fields' => $intent['fields'] ?? $intent['arguments'] ?? [],
            'missing_fields' => $intent['missing_fields'] ?? [],
            'source_type' => in_array($source, ['image', 'document'], true) ? $source : 'text',
            'document_type' => $intent['document_type'] ?? 'none',
            'summary' => $intent['summary'] ?? 'Interpretação simulada.',
        ]);
    }
}
