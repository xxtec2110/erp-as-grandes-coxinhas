<?php

namespace Database\Seeders;

use App\Models\CostCenter;
use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;

class FinanceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Funcionários', 'Aluguel/Ocupação', 'Energia', 'Água', 'Internet/Sistemas', 'Transporte/Logística', 'Manutenção', 'Impostos/Taxas', 'Fornecedores', 'Equipamentos', 'Marketing', 'Outros'] as $n) {
            FinanceCategory::query()->firstOrCreate(['name' => $n], ['active' => true]);
        }foreach (['Fábrica/Produção', 'Loja Ibirá', 'Loja Catanduva', 'Administrativo', 'Transporte/Logística'] as $n) {
            CostCenter::query()->firstOrCreate(['name' => $n], ['active' => true]);
        }
    }
}
