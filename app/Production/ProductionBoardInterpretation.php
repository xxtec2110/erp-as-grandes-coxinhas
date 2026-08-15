<?php

namespace App\Production;

use App\Agent\AiInterpretation;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class ProductionBoardInterpretation
{
    public function __construct(
        public ?CarbonImmutable $operationDate,
        public array $items,
        public string $confidence,
        public array $errors = [],
        public string $dateStatus = 'unknown',
        public bool $complete = true,
    ) {}

    public static function fromAi(AiInterpretation $interpretation): self
    {
        $fields = $interpretation->fields;
        $errors = [];
        $rawDate = $fields['production_date'] ?? $fields['operation_date'] ?? $fields['date'] ?? null;
        $dateStatus = mb_strtolower((string) ($fields['date_status'] ?? data_get($fields, '_field_statuses.production_date') ?? 'unknown'));
        $operationDate = self::parseDate($rawDate);

        if ($operationDate === null) {
            $errors[] = blank($rawDate) ? 'date_missing' : 'date_invalid';
        }
        if (! in_array($dateStatus, ['unknown', 'valid', 'clear', 'exact', 'readable'], true)) {
            $errors[] = 'date_ambiguous';
        }

        $rawItems = is_array($fields['items'] ?? null) ? $fields['items'] : [];
        if ($rawItems === []) {
            $errors[] = 'items_missing';
        }

        $items = [];
        foreach ($rawItems as $index => $rawItem) {
            if (! is_array($rawItem)) {
                $errors[] = "item_{$index}_invalid";

                continue;
            }

            $productName = $rawItem['product_name'] ?? $rawItem['name'] ?? $rawItem['product'] ?? null;
            $quantity = $rawItem['quantity'] ?? $rawItem['produced_quantity'] ?? null;
            $quantityStatus = mb_strtolower((string) ($rawItem['quantity_status'] ?? data_get($rawItem, '_field_statuses.quantity') ?? 'unknown'));

            if (! isset($rawItem['product_id']) && blank($productName)) {
                $errors[] = "item_{$index}_product_missing";
            }
            if ($quantity === null || $quantity === '') {
                $errors[] = "item_{$index}_quantity_missing";
            } elseif (! self::isWholeQuantity($quantity)) {
                $errors[] = "item_{$index}_quantity_invalid";
            }
            if (! in_array($quantityStatus, ['unknown', 'valid', 'clear', 'exact', 'readable'], true)) {
                $errors[] = "item_{$index}_quantity_ambiguous";
            }

            $item = [
                'product_name' => is_string($productName) ? trim($productName) : null,
                'quantity' => self::isWholeQuantity($quantity) ? (int) $quantity : $quantity,
                'quantity_status' => $quantityStatus,
            ];
            if (isset($rawItem['product_id']) && filter_var($rawItem['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                $item['product_id'] = (int) $rawItem['product_id'];
            }
            $items[] = $item;
        }

        $complete = ! array_key_exists('board_complete', $fields) || $fields['board_complete'] === true;
        if (! $complete || collect($interpretation->missingFields)->contains(
            fn (string $field) => $field === 'items' || str_starts_with($field, 'items.') || in_array($field, ['production_date', 'operation_date', 'date'], true)
        )) {
            $errors[] = 'board_incomplete';
        }

        return new self(
            $operationDate,
            $items,
            $interpretation->confidence,
            array_values(array_unique($errors)),
            $dateStatus,
            $complete,
        );
    }

    public function validFor(CarbonImmutable $date): bool
    {
        return $this->operationDate?->isSameDay($date) === true
            && $this->errors === []
            && $this->complete
            && $this->items !== []
            && collect($this->items)->every(
                fn (array $item) => isset($item['product_id'], $item['quantity']) && self::isWholeQuantity($item['quantity'])
            );
    }

    public function withItems(array $items): self
    {
        return new self($this->operationDate, $items, $this->confidence, $this->errors, $this->dateStatus, $this->complete);
    }

    public function total(): int
    {
        return (int) collect($this->items)->sum(fn (array $item) => (int) $item['quantity']);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim($value), config('app.timezone'));
                $expected = $format === '!Y-m-d' ? $date->format('Y-m-d') : $date->format('d/m/Y');
                if ($expected === trim($value)) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                // Tenta o próximo formato estrito.
            }
        }

        return null;
    }

    private static function isWholeQuantity(mixed $value): bool
    {
        return is_int($value) && $value >= 0
            || is_string($value)
            && preg_match('/^(0|[1-9]\d*)$/', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
    }
}
