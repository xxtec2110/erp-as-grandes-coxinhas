<?php

namespace App\Agent;

use App\Models\Ingredient;
use App\Models\PendingAgentAction;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\IngredientSemanticResolver;
use App\Services\ProductMatchService;
use App\Support\DecimalFormatter;

class CatalogAgentWorkflowService
{
    public function __construct(private PendingAgentActionService $pending, private ProductMatchService $products, private IngredientSemanticResolver $semantics) {}

    public function supports(string $tool): bool
    {
        return str_starts_with($tool, 'catalog.');
    }

    public function question(PendingAgentAction $action): ErpAgentResponse
    {
        if (empty($action->missing_fields)) {
            return $this->preview($action);
        }
        $field = $action->missing_fields[0];
        $question = match ($field) {
            'name' => str_contains($action->tool_name, 'suppliers') ? 'Qual é o nome do fornecedor?' : (str_contains($action->tool_name, 'ingredients') ? 'Qual é o nome do insumo?' : (str_contains($action->tool_name, 'preparations') ? 'Qual é o nome do preparo?' : 'Qual é o nome do produto?')),
            'selling_price' => 'Qual é o preço de venda?',
            'product_id' => 'Qual é o nome exato do produto?',
            'supplier_id' => 'Qual é o nome exato do fornecedor?',
            'ingredient_id' => 'Qual é o nome exato do insumo?',
            'preparation_id' => 'Qual é o nome exato do preparo?',
            'base_unit' => 'Qual é a unidade-base do insumo: g, ml ou un?',
            'purchase_quantity' => 'Qual é a quantidade comprada?',
            'purchase_unit' => 'Qual é a unidade da compra: kg, g, l, ml ou un?',
            'price_paid' => 'Qual foi o preço total pago?',
            'effective_date' => 'Qual é a data do preço (AAAA-MM-DD)?',
            'expected_yield' => 'Qual é o rendimento esperado?',
            'yield_unit' => 'Qual é a unidade do rendimento?',
            'total_preparation_time_minutes' => 'Qual é o tempo total de preparo em minutos?',
            default => 'Informe '.$field.'.',
        };

        return new ErpAgentResponse(true, $question, pendingAction: ['id' => $action->id]);
    }

    public function collect(PendingAgentAction $action, string $text, User $user): ErpAgentResponse
    {
        if (($action->missing_fields[0] ?? null) === 'similarity_reviewed') {
            if (mb_strtoupper(trim($text)) !== 'CRIAR NOVO') {
                return ErpAgentResponse::error('Há produto semelhante no catálogo. Responda CRIAR NOVO somente se este cadastro for realmente distinto, ou CANCELAR.', 'similar_product_requires_clarification');
            }
            $action = $this->pending->merge($action, $user, ['similarity_reviewed' => true], array_values(array_diff($action->missing_fields, ['similarity_reviewed'])));

            return $this->preview($action);
        }
        if (preg_match('/(?:TROCA|ALTERA|CORRIGE)(?: O)? PREÇO(?: PARA)?\s*(?:R\$)?\s*([0-9]+(?:[.,][0-9]{1,4})?)/ui', $text, $matches) === 1) {
            $action = $this->pending->merge($action, $user, ['selling_price' => str_replace(',', '.', $matches[1])], array_values(array_diff($action->missing_fields ?? [], ['selling_price'])));

            return empty($action->missing_fields) ? $this->preview($action) : $this->question($action);
        }
        if (empty($action->missing_fields) && str_starts_with($action->tool_name, 'catalog.preparations.') && preg_match('/^([0-9]+(?:[.,][0-9]{1,6})?)\s*(kg|g|l|ml|un)\s+de\s+(.+)$/ui', trim($text), $matches) === 1) {
            $ingredientId = $this->idByName(Ingredient::class, trim($matches[3]));
            if ($ingredientId === null) {
                $semantic = $this->semantics->resolve(trim($matches[3]));
                if ($semantic['protected'] && $semantic['status'] !== 'resolved') {
                    return ErpAgentResponse::error('O termo Catupiry representa o conceito Requeijão, mas ainda falta um vínculo operacional inequívoco. Selecione o insumo real antes de continuar.', 'ingredient_binding_required');
                }
                $ingredientId = $semantic['ingredient_id'] ?? null;
            }
            if ($ingredientId === null) {
                return ErpAgentResponse::error('Insumo não encontrado ou ambíguo. Informe o nome oficial exato.', 'ambiguous_ingredient');
            }
            $items = $action->payload['ingredients'] ?? [];
            $items[] = ['ingredient_id' => $ingredientId, 'quantity' => str_replace(',', '.', $matches[1]), 'unit' => mb_strtolower($matches[2])];
            $action = $this->pending->merge($action, $user, ['ingredients' => $items], []);

            return $this->preview($action);
        }
        $field = $action->missing_fields[0] ?? null;
        if ($field === null) {
            return $this->preview($action);
        }
        $value = match ($field) {
            'selling_price', 'purchase_quantity', 'price_paid', 'expected_yield' => $this->decimal($text),
            'total_preparation_time_minutes' => ctype_digit(trim($text)) ? (int) trim($text) : null,
            'product_id' => $this->idByName(Product::class, $text),
            'supplier_id' => $this->idByName(Supplier::class, $text),
            'ingredient_id' => $this->idByName(Ingredient::class, $text),
            'preparation_id' => $this->idByName(Preparation::class, $text),
            'base_unit', 'purchase_unit', 'yield_unit', 'effective_date', 'name' => trim($text),
            default => trim($text),
        };
        if ($value === null || $value === '') {
            return ErpAgentResponse::error('Não consegui identificar esse valor com segurança. '.$this->question($action)->message, 'ambiguous_catalog_value');
        }
        $missing = array_values(array_slice($action->missing_fields, 1));
        $action = $this->pending->merge($action, $user, [$field => $value], $missing);

        return $missing === [] ? $this->preview($action) : $this->question($action);
    }

    public function preview(PendingAgentAction $action): ErpAgentResponse
    {
        $payload = $action->payload;
        if ($action->tool_name === 'catalog.products.create' && filled($payload['name'] ?? null)) {
            $normalized = $this->products->normalize((string) $payload['name']);
            $exact = Product::query()->get()->first(fn (Product $product) => $this->products->normalize($product->name) === $normalized);
            if ($exact !== null) {
                return ErpAgentResponse::error('Já existe o produto oficial "'.$exact->name.'". Edite o cadastro existente ou cancele esta ação.', 'product_already_exists');
            }
            $match = $this->products->matchItems([['product_name' => $payload['name']]])[0]['_product_match'] ?? null;
            if ($match !== null && ($match['candidates'] ?? []) !== [] && ! ($payload['similarity_reviewed'] ?? false)) {
                $action->update(['missing_fields' => array_values(array_unique([...($action->missing_fields ?? []), 'similarity_reviewed']))]);
                $names = collect($match['candidates'])->pluck('name')->implode(', ');

                return new ErpAgentResponse(true, 'Encontrei produto semelhante: '.$names.'. Se for realmente outro produto, responda CRIAR NOVO. Caso contrário, responda CANCELAR.', 'menu', pendingAction: ['id' => $action->id]);
            }
        }
        $title = match (true) {
            $action->tool_name === 'catalog.products.create' => 'NOVO PRODUTO',
            $action->tool_name === 'catalog.products.update_price' => 'ALTERAÇÃO DE PREÇO',
            str_contains($action->tool_name, 'suppliers') => 'FORNECEDOR',
            str_contains($action->tool_name, 'ingredient_prices') => 'NOVO PREÇO DE INSUMO',
            str_contains($action->tool_name, 'ingredients') => 'INSUMO',
            str_contains($action->tool_name, 'preparations') => 'PREPARO',
            str_contains($action->tool_name, 'product_recipes') => 'FICHA TÉCNICA',
            default => 'ALTERAÇÃO DE CATÁLOGO',
        };
        $lines = [$title, ''];
        if ($action->tool_name === 'catalog.products.update_price' && isset($payload['product_id'], $payload['selling_price'])) {
            $product = Product::query()->with('currentPrice')->find($payload['product_id']);
            $lines[] = 'Produto: '.($product?->name ?? $payload['product_id']);
            $lines[] = 'Preço atual: '.($product?->currentPrice ? 'R$ '.DecimalFormatter::format($product->currentPrice->price, 2) : 'Pendente');
            $lines[] = 'Novo preço: R$ '.DecimalFormatter::format((string) $payload['selling_price'], 2);
            $lines[] = '';
        }
        foreach ($payload as $field => $value) {
            if (str_starts_with($field, '_') || $field === 'idempotency_key' || is_array($value) || ($action->tool_name === 'catalog.products.update_price' && in_array($field, ['product_id', 'selling_price'], true))) {
                continue;
            }
            $lines[] = $this->label($field).': '.$this->display($field, $value);
        }
        foreach ($payload['ingredients'] ?? [] as $item) {
            $name = Ingredient::query()->find($item['ingredient_id'])?->name ?? 'Insumo pendente';
            $lines[] = 'Ingrediente: '.$item['quantity'].' '.$item['unit'].' de '.$name;
        }
        foreach ($payload['preparations'] ?? [] as $item) {
            $name = Preparation::query()->find($item['preparation_id'])?->name ?? 'Preparo pendente';
            $lines[] = 'Preparo: '.$item['quantity'].' '.$item['unit'].' de '.$name;
        }
        if ($action->tool_name === 'catalog.products.create' && empty($payload['ingredients']) && empty($payload['preparations'])) {
            $lines[] = '';
            $lines[] = 'Produto será criado sem ficha técnica e sem custo calculado.';
        }
        $lines[] = '';
        $lines[] = 'Deseja confirmar?';
        $lines[] = '1 - SIM';
        $lines[] = '2 - NÃO';

        return new ErpAgentResponse(true, implode("\n", $lines), 'confirmation', $payload, [['id' => 'yes', 'label' => 'SIM'], ['id' => 'no', 'label' => 'NÃO']], ['id' => $action->id]);
    }

    private function decimal(string $text): ?string
    {
        if (preg_match('/([0-9]+(?:[.,][0-9]{1,6})?)/', $text, $matches) !== 1) {
            return null;
        }

        return str_replace(',', '.', $matches[1]);
    }

    private function idByName(string $model, string $name): ?int
    {
        $needle = $this->products->normalize($name);
        $matches = $model::query()->get()->filter(fn ($item) => $this->products->normalize($item->name) === $needle);

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    private function label(string $field): string
    {
        return ['name' => 'Nome', 'selling_price' => 'Preço', 'product_id' => 'Produto', 'supplier_id' => 'Fornecedor', 'ingredient_id' => 'Insumo', 'preparation_id' => 'Preparo', 'base_unit' => 'Unidade-base', 'active' => 'Ativo', 'sort_order' => 'Ordem', 'purchase_quantity' => 'Quantidade da compra', 'purchase_unit' => 'Unidade da compra', 'price_paid' => 'Preço pago', 'effective_date' => 'Data do preço', 'expected_yield' => 'Rendimento', 'yield_unit' => 'Unidade do rendimento', 'total_preparation_time_minutes' => 'Tempo de preparo'][$field] ?? $field;
    }

    private function display(string $field, mixed $value): string
    {
        if (in_array($field, ['selling_price', 'price_paid'], true)) {
            return 'R$ '.str_replace('.', ',', (string) $value);
        }
        if (str_ends_with($field, '_id')) {
            $model = match ($field) {
                'product_id' => Product::class, 'supplier_id' => Supplier::class, 'ingredient_id' => Ingredient::class, 'preparation_id' => Preparation::class, default => null
            };
            if ($model !== null) {
                return $model::query()->find($value)?->name ?? (string) $value;
            }
        }

        return is_bool($value) ? ($value ? 'Sim' : 'Não') : (string) $value;
    }
}
