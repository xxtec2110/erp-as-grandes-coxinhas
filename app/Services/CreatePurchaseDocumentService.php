<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\PurchaseDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreatePurchaseDocumentService
{
    public function __construct(private AuthorizationService $auth, private FinanceAuditService $audit, private AgentAttachmentService $attachments, private UnitConversionService $units, private IngredientPriceService $prices, private PurchaseDocumentIdentityService $identities) {}

    public function create(array $data, User $user, string $source = 'web'): PurchaseDocument
    {
        $data['currency'] ??= 'BRL';
        $validator = Validator::make($data, [
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'document_type' => ['required', Rule::in(['invoice', 'boleto', 'bill', 'receipt', 'proof', 'order', 'quote', 'other'])],
            'document_number' => ['nullable', 'string', 'max:100'],
            'series' => ['nullable', 'string', 'max:30'],
            'access_key' => ['nullable', 'string', 'max:80'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency' => ['required', Rule::in(['BRL'])],
            'gross_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'discount_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'freight_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'other_charges_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'net_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'location_id' => ['required', 'exists:locations,id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'finance_category_id' => ['nullable', 'exists:finance_categories,id'],
            'agent_attachment_id' => ['nullable', 'exists:agent_attachments,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:190'],
            'items' => ['nullable', 'array'],
            'items.*.ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'items.*.product_id' => ['nullable', 'exists:products,id'],
            'items.*.external_code' => ['nullable', 'string', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'decimal:0,6', 'gt:0'],
            'items.*.unit' => ['required', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.package_quantity' => ['nullable', 'decimal:0,6', 'gt:0'],
            'items.*.package_size' => ['nullable', 'decimal:0,6', 'gt:0'],
            'items.*.package_unit' => ['nullable', Rule::in(['kg', 'g', 'l', 'ml', 'un'])],
            'items.*.unit_price' => ['nullable', 'decimal:0,6', 'gte:0'],
            'items.*.unit_price_original' => ['nullable', 'decimal:0,6', 'gte:0'],
            'items.*.package_price' => ['nullable', 'decimal:0,4', 'gte:0'],
            'items.*.total_price' => ['nullable', 'decimal:0,2', 'gte:0'],
            'items.*.gross_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'items.*.discount_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'items.*.freight_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'items.*.other_charges_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
            'items.*.net_amount' => ['nullable', 'decimal:0,2', 'gte:0'],
        ]);
        if ($validator->fails()) {
            throw new DomainException($validator->errors()->first());
        }
        $data = $validator->validated();
        foreach ($data['items'] ?? [] as $item) {
            if (! isset($item['total_price']) && ! isset($item['net_amount']) && ! isset($item['gross_amount']) && ! isset($item['unit_price']) && ! isset($item['unit_price_original'])) {
                throw new DomainException('Cada item da compra exige um preço ou total válido.');
            }
        }
        $this->auth->authorize($user, 'purchases.create', (int) $data['location_id']);
        if (isset($data['agent_attachment_id'])) {
            $this->attachments->authorizeLink((int) $data['agent_attachment_id'], 'purchase', (int) $data['location_id'], $user);
        }

        return DB::transaction(function () use ($data, $user, $source) {
            $existing = PurchaseDocument::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load('items');
            }
            $data['identity_hash'] = $this->identities->hash($data);
            if ($data['identity_hash'] !== null && ($duplicate = PurchaseDocument::query()->where('identity_hash', $data['identity_hash'])->first()) !== null) {
                return $duplicate->load('items');
            }
            $items = $data['items'] ?? [];
            unset($data['items'], $data['_supplier_match'], $data['received'], $data['received_date']);
            $data['gross_amount'] ??= $data['total_amount'];
            $data['net_amount'] ??= $data['total_amount'];
            $data['currency'] ??= 'BRL';
            $data['source_type'] ??= $data['document_type'] === 'quote' ? 'quote' : 'purchase';
            $data['document_status'] ??= 'confirmed';
            $document = PurchaseDocument::query()->create([...$data, 'source' => $source, 'created_by' => $user->id]);
            foreach ($items as $item) {
                $purchaseItem = $document->items()->create($this->normalizeItem($item));
                if ($document->supplier_id !== null && $purchaseItem->ingredient_id !== null) {
                    $this->prices->record($purchaseItem->ingredient()->firstOrFail(), [
                        'supplier_id' => $document->supplier_id,
                        'location_id' => $document->location_id,
                        'purchase_document_id' => $document->id,
                        'purchase_item_id' => $purchaseItem->id,
                        'purchase_quantity' => $purchaseItem->quantity,
                        'purchase_unit' => $purchaseItem->unit,
                        'package_quantity' => $purchaseItem->package_quantity,
                        'package_size' => $purchaseItem->package_size,
                        'package_unit' => $purchaseItem->package_unit,
                        'unit_price_original' => $purchaseItem->unit_price_original,
                        'package_price' => $purchaseItem->package_price,
                        'price_paid' => $purchaseItem->net_amount ?? $purchaseItem->total_price,
                        'gross_total' => $purchaseItem->gross_amount ?? $purchaseItem->total_price,
                        'discount_amount' => $purchaseItem->discount_amount,
                        'freight_amount' => $purchaseItem->freight_amount,
                        'other_charges_amount' => $purchaseItem->other_charges_amount,
                        'net_total' => $purchaseItem->net_amount ?? $purchaseItem->total_price,
                        'effective_date' => $document->issue_date->toDateString(),
                        'purchase_date' => $document->issue_date->toDateString(),
                        'source_type' => $document->source_type === 'quote' ? 'quote' : 'purchase',
                        'currency' => $document->currency ?? 'BRL',
                        'created_by' => $user->id,
                        'source_channel' => $source,
                    ]);
                }
            }
            if ($document->agent_attachment_id !== null) {
                $document->loadMissing('items');
                $attachment = $this->attachments->authorizeLink($document->agent_attachment_id, 'purchase', $document->location_id, $user);
                $attachment->update(['retention_type' => 'official']);
            }
            $this->audit->record('purchase_document.created', $document, $user, $document->toArray(), null, $source, $data['idempotency_key']);

            return $document->load(['items.priceHistory', 'supplier', 'location']);
        });
    }

    private function normalizeItem(array $item): array
    {
        $item = collect($item)->reject(fn ($value, $key) => str_starts_with((string) $key, '_'))->all();
        $quantity = BigDecimal::of((string) $item['quantity']);
        $unitPrice = BigDecimal::of((string) ($item['unit_price'] ?? $item['unit_price_original'] ?? 0));
        $gross = BigDecimal::of((string) ($item['gross_amount'] ?? $item['total_price'] ?? $quantity->multipliedBy($unitPrice)));
        $discount = BigDecimal::of((string) ($item['discount_amount'] ?? 0));
        $freight = BigDecimal::of((string) ($item['freight_amount'] ?? 0));
        $charges = BigDecimal::of((string) ($item['other_charges_amount'] ?? 0));
        $net = BigDecimal::of((string) ($item['net_amount'] ?? $gross->minus($discount)->plus($freight)->plus($charges)));
        if ($quantity->isLessThanOrEqualTo(0) || $net->isNegative()) {
            throw new DomainException('Quantidade e total do item devem ser válidos.');
        }
        $item['unit_price'] = (string) $net->dividedBy($quantity, 4, RoundingMode::HalfUp);
        $item['total_price'] = (string) $net->toScale(2, RoundingMode::HalfUp);
        $item['gross_amount'] = (string) $gross->toScale(2, RoundingMode::HalfUp);
        $item['discount_amount'] = (string) $discount->toScale(2, RoundingMode::HalfUp);
        $item['freight_amount'] = (string) $freight->toScale(2, RoundingMode::HalfUp);
        $item['other_charges_amount'] = (string) $charges->toScale(2, RoundingMode::HalfUp);
        $item['net_amount'] = (string) $net->toScale(2, RoundingMode::HalfUp);
        if (isset($item['ingredient_id'])) {
            $ingredient = Ingredient::query()->findOrFail($item['ingredient_id']);
            $normalizationQuantity = $quantity;
            $normalizationUnit = (string) $item['unit'];
            if (isset($item['package_quantity'], $item['package_size'], $item['package_unit'])) {
                $normalizationQuantity = BigDecimal::of((string) $item['package_quantity'])->multipliedBy((string) $item['package_size']);
                $normalizationUnit = (string) $item['package_unit'];
            }
            $item['normalized_quantity'] = $this->units->normalize((string) $normalizationQuantity, $normalizationUnit, $ingredient->base_unit);
            $item['normalized_unit'] = $ingredient->base_unit;
            $item['normalized_unit_cost'] = $this->units->calculateBaseUnitCost((string) $net, $item['normalized_quantity']);
        }

        return $item;
    }
}
