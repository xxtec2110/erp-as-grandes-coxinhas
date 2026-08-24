<?php

namespace App\Agent;

use App\Models\Product;
use Illuminate\Support\Str;

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

        if (($intent = $this->operationalReadIntent((string) $message->text, $context['conversation'] ?? [])) !== null) {
            return $this->make($intent, $message->messageType);
        }

        return null;
    }

    private function operationalReadIntent(string $text, array $conversation = []): ?array
    {
        $plain = trim($text);
        $normalized = mb_strtolower(Str::ascii($plain));
        $arguments = $this->period($normalized);
        if (preg_match('/^e\s+(ontem|hoje|esta semana|este mes)\??$/', $normalized) === 1
            && in_array($conversation['last_tool'] ?? null, ['sales.summary', 'sales.products.ranking', 'sales.payments.summary', 'pdv.reconciliation'], true)) {
            return ['tool' => $conversation['last_tool'], 'arguments' => $arguments];
        }
        if (($location = $this->location($plain)) !== null) {
            $arguments['location_name'] = $location;
        }

        if (str_contains($normalized, 'grandchef') && (str_contains($normalized, 'diferenca') || str_contains($normalized, 'nao foram importad'))) {
            return ['tool' => 'pdv.reconciliation', 'arguments' => $arguments];
        }
        if (str_contains($normalized, 'grandchef') && (str_contains($normalized, 'funcion') || str_contains($normalized, 'sincron') || str_contains($normalized, 'bloquead'))) {
            return ['tool' => 'pdv.health', 'arguments' => collect($arguments)->except(['period'])->all()];
        }
        if (preg_match('/(?:quanto custa|qual (?:e|é) o preco(?: de| da| do)?)(?:\s+(?:a|o))?\s+(.+?)(?:\?|$)/ui', $plain, $matches) === 1) {
            return ['tool' => 'products.prices.query', 'arguments' => ['product_name' => trim($matches[1])]];
        }
        if (str_contains($normalized, 'pix') || str_contains($normalized, 'dinheiro') || str_contains($normalized, 'cartao') || str_contains($normalized, 'taxa')) {
            $arguments['payment_method'] = match (true) {
                str_contains($normalized, 'pix') => 'Pix',
                str_contains($normalized, 'dinheiro') => 'Dinheiro',
                str_contains($normalized, 'cartao') => 'Cartão',
                default => null,
            };

            return ['tool' => 'sales.payments.summary', 'arguments' => array_filter($arguments, fn (mixed $value): bool => $value !== null)];
        }
        if (str_contains($normalized, 'mais vendid') || str_contains($normalized, 'vendeu mais')) {
            return ['tool' => 'sales.products.ranking', 'arguments' => [...$arguments, 'limit' => 10]];
        }
        if (preg_match('/quantas?\s+(.+?)\s+vendemos/ui', $plain, $matches) === 1) {
            return ['tool' => 'sales.products.ranking', 'arguments' => [...$arguments, 'product_name' => trim($matches[1]), 'limit' => 10]];
        }
        if (str_contains($normalized, 'quanto vendeu') || str_contains($normalized, 'quanto vendemos') || str_contains($normalized, 'quanto faturou')) {
            return ['tool' => 'sales.summary', 'arguments' => $arguments];
        }
        if (preg_match('/(?:estoque|saldo) (?:do |de )?(?:insumo|ingrediente)\s+(.+?)(?:\s+em\s+.+)?(?:\?|$)/ui', $plain, $matches) === 1) {
            return ['tool' => 'stock.ingredients.query', 'arguments' => [...collect($arguments)->except('period')->all(), 'ingredient_name' => trim($matches[1])]];
        }
        if (preg_match('/quanto (?:eu )?tenho de\s+(.+?)(?:\s+em\s+.+)?(?:\?|$)/ui', $plain, $matches) === 1) {
            return ['tool' => 'stock.products.query', 'arguments' => [...collect($arguments)->except('period')->all(), 'product_name' => trim($matches[1])]];
        }
        if (str_contains($normalized, 'produtos zerados') || str_contains($normalized, 'estoque dos produtos')) {
            return ['tool' => 'stock.products.query', 'arguments' => [...collect($arguments)->except('period')->all(), 'zero_only' => str_contains($normalized, 'zerad')]];
        }

        return null;
    }

    /** @return array{period: string} */
    private function period(string $text): array
    {
        return ['period' => match (true) {
            str_contains($text, 'ontem') => 'yesterday',
            str_contains($text, 'semana') => 'week',
            str_contains($text, 'mes') => 'month',
            default => 'today',
        }];
    }

    private function location(string $text): ?string
    {
        if (preg_match('/\s(?:em|na|no)\s+([\pL][\pL\s\/\-]+?)(?:\?|\.|$)/ui', $text, $matches) === 1) {
            return trim($matches[1]);
        }
        if (preg_match('/grandchef\s+(?:de|da|do)\s+(.+?)\s+(?:esta|está|funciona|tem)/ui', $text, $matches) === 1) {
            return trim($matches[1]);
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
