<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Preparation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\Cnpj;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CatalogAgentToolService
{
    public function __construct(
        private CatalogAdminAuditService $audit,
        private ProductCatalogService $products,
        private ProductPriceService $productPrices,
        private SupplierCatalogService $suppliers,
        private IngredientCatalogService $ingredients,
        private IngredientPriceService $ingredientPrices,
        private PreparationCatalogService $preparations,
        private ProductRecipeService $recipes,
    ) {}

    public function execute(string $tool, array $input, User $user, string $channel = 'agent'): Model
    {
        $key = (string) ($input['idempotency_key'] ?? throw new \DomainException('Chave de idempotência obrigatória.'));
        $before = $this->target($tool, $input);

        return $this->audit->execute($user, $channel, $tool, $key, fn (): Model => $this->perform($tool, $input, $user), $before, ['location_id' => $input['location_id'] ?? null]);
    }

    private function perform(string $tool, array $input, User $user): Model
    {
        return match ($tool) {
            'catalog.products.create' => $this->createProduct($input, $user),
            'catalog.products.update' => $this->updateProduct($input, $user),
            'catalog.products.update_price' => $this->updateProductPrice($input, $user),
            'catalog.product_aliases.create' => $this->createProductAlias($input, $user),
            'catalog.suppliers.create' => $this->createSupplier($input),
            'catalog.suppliers.update' => $this->updateSupplier($input),
            'catalog.ingredients.create' => $this->createIngredient($input),
            'catalog.ingredients.update' => $this->updateIngredient($input),
            'catalog.ingredient_prices.add' => $this->addIngredientPrice($input),
            'catalog.preparations.create' => $this->createPreparation($input),
            'catalog.preparations.update' => $this->updatePreparation($input),
            'catalog.product_recipes.create', 'catalog.product_recipes.update' => $this->saveRecipe($tool, $input, $user),
            default => throw new \DomainException('Ferramenta administrativa sem executor oficial.'),
        };
    }

    private function createProduct(array $input, User $user): Product
    {
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:255'], 'selling_price' => ['required', 'decimal:0,4', 'gt:0'], 'product_category_id' => ['nullable', 'exists:product_categories,id'], 'stock_unit' => ['nullable', Rule::in(['g', 'ml', 'un'])], 'sort_order' => ['nullable', 'integer', 'min:1'], 'active' => ['nullable', 'boolean'], 'aliases' => ['nullable', 'array'], 'aliases.*' => ['string', 'max:255']])->validate();
        $aliases = $data['aliases'] ?? [];
        unset($data['aliases']);

        return $this->products->create([...$data, 'stock_unit' => $data['stock_unit'] ?? 'un', 'active' => $data['active'] ?? true], $aliases, $user, 'agent', $input['idempotency_key']);
    }

    private function updateProduct(array $input, User $user): Product
    {
        $product = Product::query()->findOrFail($input['product_id']);
        $data = Validator::make($input, ['product_id' => ['required', 'exists:products,id'], 'name' => ['sometimes', 'string', 'max:255'], 'product_category_id' => ['sometimes', 'nullable', 'exists:product_categories,id'], 'stock_unit' => ['sometimes', Rule::in(['g', 'ml', 'un'])], 'sort_order' => ['sometimes', 'nullable', 'integer', 'min:1'], 'active' => ['sometimes', 'boolean'], 'aliases' => ['sometimes', 'array'], 'aliases.*' => ['string', 'max:255']])->validate();
        unset($data['product_id']);
        $aliases = $data['aliases'] ?? null;
        unset($data['aliases']);

        return $this->products->update($product, $data, $aliases, $user, 'agent', $input['idempotency_key']);
    }

    private function updateProductPrice(array $input, User $user): Model
    {
        $data = Validator::make($input, ['product_id' => ['required', 'exists:products,id'], 'selling_price' => ['required', 'decimal:0,4', 'gt:0'], 'effective_date' => ['nullable', 'date']])->validate();

        return $this->productPrices->record(Product::query()->findOrFail($data['product_id']), $data['selling_price'], $user, 'agent', $input['idempotency_key'].':price', $data['effective_date'] ?? null);
    }

    private function createProductAlias(array $input, User $user): Product
    {
        $data = Validator::make($input, ['product_id' => ['required', 'exists:products,id'], 'alias' => ['required', 'string', 'max:255']])->validate();
        $product = Product::query()->with('aliases')->findOrFail($data['product_id']);

        return $this->products->update($product, [], [...$product->aliases->pluck('name')->all(), $data['alias']], $user, 'agent', $input['idempotency_key']);
    }

    private function createSupplier(array $input): Supplier
    {
        $data = $this->supplierData($input);

        return $this->suppliers->create($data);
    }

    private function updateSupplier(array $input): Supplier
    {
        $supplier = Supplier::query()->findOrFail($input['supplier_id']);

        return $this->suppliers->update($supplier, $this->supplierData($input, true));
    }

    private function supplierData(array $input, bool $updating = false): array
    {
        if (isset($input['document_number'])) {
            $input['document_number'] = preg_replace('/\D+/', '', (string) $input['document_number']) ?: null;
            $input['document_type'] = $input['document_number'] ? 'cnpj' : null;
        }
        $rules = ['name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'], 'document_type' => ['nullable', Rule::in(['cnpj'])], 'document_number' => ['nullable', 'string', 'max:20', new Cnpj], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:2000'], 'active' => ['nullable', 'boolean']];
        $data = Validator::make($input, $rules)->validate();
        unset($data['supplier_id']);
        if (! $updating) {
            $data['active'] ??= true;
        }

        return $data;
    }

    private function createIngredient(array $input): Ingredient
    {
        return $this->ingredients->create($this->ingredientData($input));
    }

    private function updateIngredient(array $input): Ingredient
    {
        $ingredient = Ingredient::query()->findOrFail($input['ingredient_id']);

        return $this->ingredients->update($ingredient, $this->ingredientData($input, true));
    }

    private function ingredientData(array $input, bool $updating = false): array
    {
        $data = Validator::make($input, ['name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'], 'ingredient_category_id' => ['nullable', 'exists:ingredient_categories,id'], 'brand' => ['nullable', 'string', 'max:255'], 'base_unit' => [$updating ? 'sometimes' : 'required', Rule::in(['g', 'ml', 'un'])], 'notes' => ['nullable', 'string', 'max:2000'], 'active' => ['nullable', 'boolean']])->validate();
        if (! $updating) {
            $data['active'] ??= true;
        }

        return $data;
    }

    private function addIngredientPrice(array $input): Model
    {
        $data = Validator::make($input, ['ingredient_id' => ['required', 'exists:ingredients,id'], 'supplier_id' => ['required', 'exists:suppliers,id'], 'purchase_quantity' => ['required', 'decimal:0,6', 'gt:0'], 'purchase_unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])], 'price_paid' => ['required', 'decimal:0,4', 'gt:0'], 'effective_date' => ['required', 'date'], 'is_current' => ['nullable', 'boolean']])->validate();
        $ingredient = Ingredient::query()->findOrFail($data['ingredient_id']);
        unset($data['ingredient_id']);

        return $this->ingredientPrices->record($ingredient, [...$data, 'is_current' => true]);
    }

    private function createPreparation(array $input): Preparation
    {
        return $this->preparations->create($this->preparationData($input));
    }

    private function updatePreparation(array $input): Preparation
    {
        return $this->preparations->update(Preparation::query()->findOrFail($input['preparation_id']), $this->preparationData($input, true));
    }

    private function preparationData(array $input, bool $updating = false): array
    {
        return Validator::make($input, ['name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'initial_quantity' => ['nullable', 'decimal:0,6', 'gt:0'], 'initial_unit' => ['nullable', Rule::in(['kg', 'g', 'l', 'ml', 'un'])], 'expected_yield' => [$updating ? 'sometimes' : 'required', 'decimal:0,6', 'gt:0'], 'yield_unit' => [$updating ? 'sometimes' : 'required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])], 'actual_final_quantity' => ['nullable', 'decimal:0,6', 'gt:0'], 'total_preparation_time_minutes' => [$updating ? 'sometimes' : 'required', 'integer', 'min:1'], 'notes' => ['nullable', 'string'], 'active' => ['nullable', 'boolean'], 'ingredients' => ['sometimes', 'array'], 'ingredients.*.ingredient_id' => ['required', 'exists:ingredients,id'], 'ingredients.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'ingredients.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])]])->validate() + ($updating ? [] : ['active' => true]);
    }

    private function saveRecipe(string $tool, array $input, User $user): Model
    {
        $product = Product::query()->with(['recipe.ingredients', 'recipe.preparations'])->findOrFail($input['product_id']);
        if ($tool === 'catalog.product_recipes.create' && $product->recipe !== null) {
            throw new \DomainException('O produto já possui ficha técnica. Use a alteração da ficha existente.');
        }
        if ($tool === 'catalog.product_recipes.update' && $product->recipe === null) {
            throw new \DomainException('O produto ainda não possui ficha técnica. Use a criação de ficha.');
        }
        $data = Validator::make($input, ['product_id' => ['required', 'exists:products,id'], 'final_weight_grams' => ['nullable', 'decimal:0,6', 'gt:0'], 'yield_quantity' => ['required', 'decimal:0,6', 'gt:0'], 'technical_loss_percentage' => ['required', 'decimal:0,6', 'gte:0', 'lt:100'], 'packaging_cost' => ['required', 'decimal:0,6', 'gte:0'], 'selling_price' => ['nullable', 'decimal:0,4', 'gt:0'], 'notes' => ['nullable', 'string'], 'ingredients' => ['nullable', 'array'], 'ingredients.*.ingredient_id' => ['required', 'exists:ingredients,id'], 'ingredients.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'ingredients.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])], 'preparations' => ['nullable', 'array'], 'preparations.*.preparation_id' => ['required', 'exists:preparations,id'], 'preparations.*.quantity' => ['required', 'decimal:0,6', 'gt:0'], 'preparations.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])]])->validate();
        if ($tool === 'catalog.product_recipes.update') {
            $data['ingredients'] ??= $product->recipe->ingredients->map->only(['ingredient_id', 'quantity', 'unit'])->all();
            $data['preparations'] ??= $product->recipe->preparations->map->only(['preparation_id', 'quantity', 'unit'])->all();
        }
        unset($data['product_id']);

        return $this->recipes->save($product, $data, $user, 'agent', $input['idempotency_key']);
    }

    private function target(string $tool, array $input): ?Model
    {
        return match (true) {
            isset($input['product_id']) && str_contains($tool, 'product') => Product::query()->find($input['product_id']),
            isset($input['supplier_id']) => Supplier::query()->find($input['supplier_id']),
            isset($input['ingredient_id']) => Ingredient::query()->find($input['ingredient_id']),
            isset($input['preparation_id']) => Preparation::query()->find($input['preparation_id']),
            default => null,
        };
    }
}
