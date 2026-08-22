<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\ProductCategory;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PdvProductOnboardingService
{
    /** @return array<string, mixed> */
    public function context(PdvConnection $connection, string $externalProductId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$fromUtc, $toUtc] = $this->utcPeriod($from, $to);
        $rows = DB::table('pdv_order_items as items')
            ->join('pdv_orders as orders', 'orders.id', '=', 'items.pdv_order_id')
            ->where('orders.pdv_connection_id', $connection->id)
            ->whereBetween('orders.external_completed_at', [$fromUtc, $toUtc])
            ->where('items.external_product_id', $externalProductId)
            ->where('items.present_in_latest', true)
            ->where('items.cancelled', false)
            ->orderByDesc('orders.external_completed_at')
            ->orderByDesc('items.id')
            ->get(['items.external_product_id', 'items.external_product_code', 'items.description', 'items.quantity', 'items.total', 'items.unit_price', 'items.pdv_order_id', 'orders.external_completed_at']);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['external_product_id' => 'O produto externo não pertence ao staging e período informados.']);
        }

        $first = $rows->first();
        $prices = $this->priceDetails($rows);
        $category = $this->suggestedCategory((string) $first->description);

        return [
            'connection' => $connection,
            'external_product_id' => (string) $first->external_product_id,
            'external_product_code' => $first->external_product_code === null ? null : (string) $first->external_product_code,
            'description' => (string) $first->description,
            'suggested_name' => $this->suggestedName((string) $first->description),
            'quantity_total' => (string) $rows->reduce(fn (BigDecimal $total, object $row): BigDecimal => $total->plus((string) $row->quantity), BigDecimal::zero()),
            'value_total' => (string) $rows->reduce(fn (BigDecimal $total, object $row): BigDecimal => $total->plus((string) $row->total), BigDecimal::zero()),
            'order_count' => $rows->pluck('pdv_order_id')->unique()->count(),
            'prices' => $prices,
            'suggested_category' => $category,
            'category_gate' => $this->isBeverage((string) $first->description) && $category === null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    public function assertCategoryAllowed(array $context, ?int $categoryId): void
    {
        if (! $this->isBeverage((string) $context['description'])) {
            return;
        }

        $category = $categoryId === null ? null : ProductCategory::query()->whereKey($categoryId)->where('active', true)->first();
        if ($category === null || ! $this->isBeverageCategory($category->name)) {
            throw ValidationException::withMessages(['product_category_id' => 'Categoria oficial para bebidas precisa ser definida antes de criar este Product.']);
        }
    }

    /** @param Collection<int, ProductCategory>|null $categories */
    public function suggestedCategory(string $description, ?Collection $categories = null): ?ProductCategory
    {
        if (! $this->isBeverage($description)) {
            return null;
        }

        return ($categories ?? ProductCategory::query()->where('active', true)->orderBy('name')->get())
            ->first(fn (ProductCategory $category): bool => $this->isBeverageCategory($category->name));
    }

    /** @param Collection<int, object> $rows
     * @return array{observed:array<int,string>,minimum:?string,maximum:?string,latest:?string,same:bool}
     */
    public function priceDetails(Collection $rows): array
    {
        $observed = $rows->pluck('unit_price')->filter(fn ($price): bool => $price !== null)
            ->map(fn ($price): string => (string) BigDecimal::of((string) $price)->toScale(4))
            ->unique()->values();
        $sorted = $observed->sort(fn (string $left, string $right): int => BigDecimal::of($left)->compareTo(BigDecimal::of($right)))->values();

        return [
            'observed' => $sorted->all(),
            'minimum' => $sorted->first(),
            'maximum' => $sorted->last(),
            'latest' => $rows->first(fn (object $row): bool => $row->unit_price !== null)?->unit_price,
            'same' => $sorted->count() === 1,
        ];
    }

    public function suggestedName(string $description): string
    {
        $withoutCode = preg_replace('/^\s*\d+\s*[-–—]\s*/u', '', trim($description)) ?: trim($description);

        return Str::title(mb_strtolower($withoutCode));
    }

    public function isBeverage(string $value): bool
    {
        $normalized = $this->normalize($value);

        return collect(['agua', 'suco', 'coca-cola', 'coca cola', 'cerveja', 'sprite', 'refrigerante', 'bebida'])
            ->contains(fn (string $token): bool => str_contains($normalized, $token));
    }

    private function isBeverageCategory(string $value): bool
    {
        $normalized = $this->normalize($value);

        return collect(['bebida', 'bebidas', 'refrigerante', 'refrigerantes', 'drinks'])
            ->contains(fn (string $token): bool => $normalized === $token || str_contains($normalized, $token));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function utcPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        return [$from->setTimezone($timezone)->startOfDay()->utc(), $to->setTimezone($timezone)->endOfDay()->utc()];
    }
}
