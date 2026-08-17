<?php

namespace App\Services;

use App\Agent\AiInterpretation;
use App\Models\Ingredient;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PurchaseDocumentInterpretationService
{
    public function __construct(private UnitConversionService $units) {}

    public function normalize(AiInterpretation $interpretation): array
    {
        $fields = $interpretation->fields;
        $documentType = $this->documentType($interpretation->documentType, (string) ($fields['document_type'] ?? ''));
        $warnings = array_values(array_filter($fields['warnings'] ?? [], 'is_string'));
        $missing = array_values(array_unique(array_filter($interpretation->missingFields, 'is_string')));
        $ambiguous = array_values(array_filter($fields['ambiguous_fields'] ?? [], 'is_string'));
        $items = [];
        foreach (array_values(array_filter($fields['items'] ?? [], 'is_array')) as $index => $item) {
            $normalized = $this->item($item, $index + 1);
            if ($normalized['ingredient_id'] === null) {
                $missing[] = 'items.'.($index + 1).'.ingredient_id';
            }
            foreach (['quantity', 'unit', 'net_amount'] as $required) {
                if ($normalized[$required] === null) {
                    $missing[] = 'items.'.($index + 1).'.'.$required;
                }
            }
            $packageFields = ['package_quantity', 'package_size', 'package_unit'];
            $providedPackageFields = collect($packageFields)->filter(fn (string $field) => $normalized[$field] !== null)->count();
            if ($providedPackageFields > 0 && $providedPackageFields < count($packageFields)) {
                $warnings[] = 'A embalagem da linha '.($index + 1).' está incompleta.';
                foreach ($packageFields as $field) {
                    if ($normalized[$field] === null) {
                        $missing[] = 'items.'.($index + 1).'.'.$field;
                    }
                }
            }
            if ($normalized['ingredient_id'] !== null && $normalized['normalized_quantity'] === null) {
                $ambiguous[] = 'items.'.($index + 1).'.unit';
            }
            $items[] = $normalized;
        }
        $lineKeys = collect($items)->map(fn (array $item) => mb_strtolower(trim((string) $item['external_code'].'|'.$item['description'].'|'.$item['quantity'].'|'.$item['net_amount'])));
        if ($lineKeys->duplicates()->isNotEmpty()) {
            $warnings[] = 'Há linhas possivelmente repetidas entre as páginas; confirme antes de salvar.';
            $ambiguous[] = 'items_overlap';
        }
        if (($fields['pages_complete'] ?? true) === false) {
            $warnings[] = 'O conjunto de páginas parece incompleto.';
            $missing[] = 'document_pages';
        }

        $gross = $this->decimal($fields['gross_amount'] ?? $fields['subtotal'] ?? null);
        $discount = $this->decimal($fields['discount_amount'] ?? $fields['discount'] ?? 0) ?? '0.00';
        $freight = $this->decimal($fields['freight_amount'] ?? $fields['freight'] ?? 0) ?? '0.00';
        $charges = $this->decimal($fields['other_charges_amount'] ?? $fields['charges'] ?? 0) ?? '0.00';
        $total = $this->decimal($fields['total_amount'] ?? $fields['net_amount'] ?? null);
        $lineTotal = collect($items)->reduce(fn (BigDecimal $sum, array $item) => $item['net_amount'] === null ? $sum : $sum->plus($item['net_amount']), BigDecimal::zero());
        if ($gross === null && ! $lineTotal->isZero()) {
            $gross = (string) $lineTotal->toScale(2, RoundingMode::HalfUp);
        }
        $reconciled = $gross === null ? null : BigDecimal::of($gross)->minus($discount)->plus($freight)->plus($charges)->toScale(2, RoundingMode::HalfUp);
        if ($total === null && $reconciled !== null) {
            $total = (string) $reconciled;
        }
        if ($total !== null && $reconciled !== null && BigDecimal::of($total)->minus($reconciled)->abs()->isGreaterThan('0.02')) {
            $warnings[] = 'O total do documento não confere com itens, descontos, frete e acréscimos.';
            $ambiguous[] = 'total_amount';
        }
        if ($items === [] && in_array($documentType, ['purchase_invoice', 'purchase_receipt', 'purchase_order', 'quotation'], true)) {
            $missing[] = 'items';
        }
        foreach (['issue_date', 'total_amount'] as $required) {
            if (blank($fields[$required] ?? ($required === 'total_amount' ? $total : null))) {
                $missing[] = $required;
            }
        }
        if (in_array($documentType, ['purchase_invoice', 'purchase_receipt', 'purchase_order'], true)) {
            $missing[] = 'received';
        }

        return [
            'document_type' => $documentType,
            'supplier_id' => isset($fields['supplier_id']) ? (int) $fields['supplier_id'] : null,
            'supplier_name_extracted' => $fields['supplier_name'] ?? $fields['supplier_legal_name'] ?? null,
            'supplier_document_extracted' => $fields['supplier_document_number'] ?? $fields['supplier_tax_id'] ?? $fields['cnpj'] ?? null,
            'document_number' => $fields['document_number'] ?? null,
            'series' => $fields['series'] ?? null,
            'access_key' => $fields['access_key'] ?? null,
            'issue_date' => $fields['issue_date'] ?? null,
            'currency' => mb_strtoupper((string) ($fields['currency'] ?? 'BRL')),
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'freight_amount' => $freight,
            'other_charges_amount' => $charges,
            'total_amount' => $total,
            'confidence' => $interpretation->confidence,
            'field_confidences' => is_array($fields['field_confidences'] ?? null) ? $fields['field_confidences'] : [],
            'warnings' => array_values(array_unique($warnings)),
            'missing_fields' => array_values(array_unique($missing)),
            'ambiguous_fields' => array_values(array_unique($ambiguous)),
            'items' => $items,
            'raw' => $interpretation->toArray(),
        ];
    }

    private function item(array $item, int $line): array
    {
        $quantity = $this->decimal($item['quantity'] ?? null, 6);
        $unit = isset($item['unit']) ? mb_strtolower(trim((string) $item['unit'])) : null;
        $packageQuantity = $this->decimal($item['package_quantity'] ?? null, 6);
        $packageSize = $this->decimal($item['package_size'] ?? null, 6);
        $packageUnit = isset($item['package_unit']) ? mb_strtolower(trim((string) $item['package_unit'])) : null;
        if ($quantity === null && $packageQuantity !== null && $packageSize !== null) {
            $quantity = (string) BigDecimal::of($packageQuantity)->multipliedBy($packageSize)->toScale(6, RoundingMode::HalfUp);
            $unit = $packageUnit;
        }
        $unitPrice = $this->decimal($item['unit_price_original'] ?? $item['unit_price'] ?? null, 6);
        $gross = $this->decimal($item['gross_amount'] ?? $item['total_price'] ?? null);
        if ($gross === null && $quantity !== null && $unitPrice !== null) {
            $gross = (string) BigDecimal::of($quantity)->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
        }
        $discount = $this->decimal($item['discount_amount'] ?? 0) ?? '0.00';
        $freight = $this->decimal($item['freight_amount'] ?? 0) ?? '0.00';
        $charges = $this->decimal($item['other_charges_amount'] ?? 0) ?? '0.00';
        $net = $this->decimal($item['net_amount'] ?? null);
        if ($net === null && $gross !== null) {
            $net = (string) BigDecimal::of($gross)->minus($discount)->plus($freight)->plus($charges)->toScale(2, RoundingMode::HalfUp);
        }
        $ingredientId = isset($item['ingredient_id']) ? (int) $item['ingredient_id'] : null;
        $normalizedQuantity = null;
        $normalizedUnit = null;
        $normalizedCost = null;
        $warnings = array_values(array_filter($item['warnings'] ?? [], 'is_string'));
        $normalizationQuantity = $quantity;
        $normalizationUnit = $unit;
        if ($packageQuantity !== null && $packageSize !== null && $packageUnit !== null) {
            $normalizationQuantity = (string) BigDecimal::of($packageQuantity)->multipliedBy($packageSize)->toScale(6, RoundingMode::HalfUp);
            $normalizationUnit = $packageUnit;
        }
        if ($ingredientId !== null && $normalizationQuantity !== null && $normalizationUnit !== null) {
            $ingredient = Ingredient::query()->find($ingredientId);
            if ($ingredient !== null && $this->units->areCompatible($normalizationUnit, $ingredient->base_unit)) {
                $normalizedQuantity = $this->units->normalize($normalizationQuantity, $normalizationUnit, $ingredient->base_unit);
                $normalizedUnit = $ingredient->base_unit;
                $normalizedCost = $net === null ? null : $this->units->calculateBaseUnitCost($net, $normalizedQuantity);
            } else {
                $warnings[] = 'Unidade incompatível com o insumo selecionado.';
            }
        }

        return [
            'line_number' => $line,
            'external_code' => $item['external_code'] ?? $item['product_code'] ?? null,
            'description' => trim((string) ($item['description'] ?? $item['ingredient_name'] ?? 'Item sem descrição')),
            'ingredient_id' => $ingredientId,
            'mapping_status' => $ingredientId === null ? 'unresolved' : (string) data_get($item, '_ingredient_match.source', data_get($item, '_ingredient_match.status', 'resolved')),
            'quantity' => $quantity,
            'unit' => $unit,
            'package_quantity' => $packageQuantity,
            'package_size' => $packageSize,
            'package_unit' => $packageUnit,
            'unit_price_original' => $unitPrice,
            'package_price' => $this->decimal($item['package_price'] ?? null, 4),
            'gross_amount' => $gross,
            'discount_amount' => $discount,
            'freight_amount' => $freight,
            'other_charges_amount' => $charges,
            'net_amount' => $net,
            'normalized_quantity' => $normalizedQuantity,
            'normalized_unit' => $normalizedUnit,
            'normalized_unit_cost' => $normalizedCost,
            'confidence' => $this->decimal($item['confidence'] ?? null, 6),
            'warnings' => $warnings,
        ];
    }

    private function documentType(string $schemaType, string $fieldType): string
    {
        return match ($fieldType !== '' ? $fieldType : $schemaType) {
            'invoice', 'purchase_invoice', 'purchase_document' => 'purchase_invoice',
            'receipt', 'purchase_receipt' => 'purchase_receipt',
            'order', 'purchase_order' => 'purchase_order',
            'quote', 'quotation' => 'quotation',
            'production_board' => 'production_board',
            'non_business_image' => 'non_business_image',
            default => 'unknown_document',
        };
    }

    private function decimal(mixed $value, int $scale = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', (string) $value) ?? '';
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }
        $normalized = str_replace(',', '.', preg_replace('/[^0-9,.-]+/', '', $normalized) ?? '');
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            return null;
        }

        return (string) BigDecimal::of($normalized)->toScale($scale, RoundingMode::HalfUp);
    }
}
