<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Str;

class SupplierMatchService
{
    public function match(?string $name): array
    {
        if (! filled($name)) {
            return ['status' => 'missing', 'supplier_id' => null, 'candidates' => []];
        }
        $needle = $this->normalize($name);
        $suppliers = Supplier::query()->where('active', true)->get();
        $exact = $suppliers->filter(fn (Supplier $supplier) => $this->normalize($supplier->name) === $needle);
        if ($exact->count() === 1) {
            return ['status' => 'exact', 'supplier_id' => $exact->first()->id, 'candidates' => []];
        }
        $candidates = $suppliers->filter(function (Supplier $supplier) use ($needle): bool {
            $candidate = $this->normalize($supplier->name);
            $max = max(mb_strlen($needle), mb_strlen($candidate));

            return $max > 0 && (1 - levenshtein($needle, $candidate) / $max) >= 0.65;
        })->map(fn (Supplier $supplier) => ['id' => $supplier->id, 'name' => $supplier->name])->values()->all();

        return ['status' => count($candidates) > 0 ? 'ambiguous' : 'not_found', 'supplier_id' => null, 'candidates' => $candidates];
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii(trim($value)))) ?? '';
    }
}
