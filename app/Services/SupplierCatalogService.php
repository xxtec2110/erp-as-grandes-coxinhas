<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierCatalogService
{
    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data): Supplier {
            $this->assertUnique($data);

            return Supplier::query()->create($data);
        });
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data): Supplier {
            $this->assertUnique($data, $supplier);
            $supplier->update($data);

            return $supplier->refresh();
        });
    }

    private function assertUnique(array $data, ?Supplier $except = null): void
    {
        $normalized = $this->normalize((string) $data['name']);
        $suppliers = Supplier::query()->when($except, fn ($query) => $query->whereKeyNot($except->id))->get();
        if ($suppliers->contains(fn (Supplier $supplier) => $this->normalize($supplier->name) === $normalized)) {
            throw ValidationException::withMessages(['name' => 'Já existe um fornecedor com este nome.']);
        }
        if (filled($data['document_number'] ?? null) && $suppliers->contains('document_number', $data['document_number'])) {
            throw ValidationException::withMessages(['document_number' => 'Este CNPJ já pertence a outro fornecedor.']);
        }
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($value))) ?? '';
    }
}
