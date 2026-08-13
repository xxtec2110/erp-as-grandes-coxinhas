<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Str;

class SupplierMatchService
{
    public function match(?string $name, ?string $documentNumber = null): array
    {
        $suppliers = Supplier::query()->where('active', true)->get();
        $fiscal = preg_replace('/\D+/', '', (string) $documentNumber) ?: null;
        $needle = filled($name) ? $this->normalize($name) : null;
        if ($fiscal !== null) {
            $fiscalMatch = $suppliers->firstWhere('document_number', $fiscal);
            $nameMatch = $needle ? $suppliers->first(fn (Supplier $supplier) => $this->normalize($supplier->name) === $needle) : null;
            if ($fiscalMatch !== null && $nameMatch !== null && $fiscalMatch->id !== $nameMatch->id) {
                return ['status' => 'conflict', 'supplier_id' => null, 'candidates' => [['id' => $fiscalMatch->id, 'name' => $fiscalMatch->name], ['id' => $nameMatch->id, 'name' => $nameMatch->name]]];
            }
            if ($fiscalMatch !== null) {
                return ['status' => 'fiscal_exact', 'supplier_id' => $fiscalMatch->id, 'candidates' => []];
            }

            return ['status' => 'fiscal_not_found', 'supplier_id' => null, 'candidates' => $nameMatch ? [['id' => $nameMatch->id, 'name' => $nameMatch->name]] : []];
        }
        if ($needle === null) {
            return ['status' => 'missing', 'supplier_id' => null, 'candidates' => []];
        }
        $exact = $suppliers->filter(fn (Supplier $supplier) => $this->normalize($supplier->name) === $needle);
        if ($exact->count() === 1) {
            return ['status' => 'name_exact', 'supplier_id' => $exact->first()->id, 'candidates' => []];
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
