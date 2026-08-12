<?php

namespace Database\Seeders;

use App\Models\Acquirer;
use App\Models\CardBrand;
use Illuminate\Database\Seeder;

class PaymentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Stone', 'Rede', 'PagSeguro', 'Mercado Pago'] as $name) {
            Acquirer::query()->firstOrCreate(['name' => $name], ['active' => true]);
        }
        foreach (['Visa', 'Mastercard', 'Elo', 'Hipercard', 'American Express', 'Outros'] as $name) {
            CardBrand::query()->firstOrCreate(['name' => $name], ['active' => true]);
        }
    }
}
