<?php

namespace App\Agent;

use App\Models\Product;

class FakeAiProvider implements AiProviderInterface
{
    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?array
    {
        if (isset($message->metadata['fake_intent'])) {
            return $message->metadata['fake_intent'];
        }
        if (mb_strtoupper(trim($message->text ?? '')) === 'CRIAR CONTA TESTE') {
            return ['tool' => 'finance.payables.create', 'arguments' => ['description' => 'Conta criada no simulador', 'expected_amount' => '10', 'competency_date' => now()->toDateString(), 'due_date' => now()->addDays(5)->toDateString(), 'recurring' => false, 'recurrence_rule' => null, 'supplier_id' => null, 'cost_center_id' => null, 'finance_category_id' => null, 'notes' => 'Simulação local']];
        }
        if (preg_match('/^PRODUZIMOS\s+([0-9]+(?:[.,][0-9]+)?)\s+(.+)$/ui', trim($message->text ?? ''), $matches) === 1) {
            $name = mb_strtolower(trim($matches[2]));
            $product = Product::query()->where('active', true)->get()->first(
                fn (Product $product) => mb_strtolower($product->name) === $name
            );
            if ($product !== null) {
                return ['tool' => 'production.plan', 'arguments' => [
                    'product_id' => $product->id,
                    'planned_quantity' => str_replace(',', '.', $matches[1]),
                    'operation_date' => now()->toDateString(),
                ]];
            }
        }

        return null;
    }
}
