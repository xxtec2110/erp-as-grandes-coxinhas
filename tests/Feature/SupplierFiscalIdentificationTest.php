<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierMatchService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierFiscalIdentificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_supplier_accepts_valid_masked_cnpj_and_normalizes_it(): void
    {
        $user = User::factory()->unprivileged()->create();
        $user->permissions()->attach(Permission::query()->where('name', 'suppliers.manage')->firstOrFail(), ['allowed' => true]);
        $this->actingAs($user)->post(route('suppliers.store'), ['name' => 'Fornecedor Fiscal', 'document_number' => '11.222.333/0001-81', 'active' => '1'])->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', ['document_type' => 'cnpj', 'document_number' => '11222333000181']);
    }

    public function test_invalid_and_duplicate_active_cnpj_are_rejected_but_legacy_null_is_allowed(): void
    {
        $user = User::factory()->create();
        Supplier::query()->create(['name' => 'Legado sem documento', 'active' => true]);
        Supplier::query()->create(['name' => 'Existente', 'document_type' => 'cnpj', 'document_number' => '11222333000181', 'active' => true]);

        $this->actingAs($user)->post(route('suppliers.store'), ['name' => 'Inválido', 'document_number' => '12.345.678/0001-90', 'active' => '1'])->assertSessionHasErrors('document_number');
        $this->actingAs($user)->post(route('suppliers.store'), ['name' => 'Duplicado', 'document_number' => '11.222.333/0001-81', 'active' => '1'])->assertSessionHasErrors('document_number');
        $this->assertDatabaseHas('suppliers', ['name' => 'Legado sem documento', 'document_number' => null]);
    }

    public function test_fiscal_match_has_priority_and_name_conflict_requires_review(): void
    {
        $fiscal = Supplier::query()->create(['name' => 'Fornecedor A', 'document_type' => 'cnpj', 'document_number' => '11222333000181', 'active' => true]);
        Supplier::query()->create(['name' => 'Fornecedor B', 'document_type' => 'cnpj', 'document_number' => '11444777000161', 'active' => true]);
        $service = app(SupplierMatchService::class);

        $this->assertSame($fiscal->id, $service->match('Nome OCR imperfeito', '11.222.333/0001-81')['supplier_id']);
        $this->assertSame('conflict', $service->match('Fornecedor B', '11.222.333/0001-81')['status']);
        $this->assertSame('fiscal_not_found', $service->match(null, '45.723.174/0001-10')['status']);
        $this->assertSame($fiscal->id, $service->match('Fornecedor A')['supplier_id']);
    }
}
