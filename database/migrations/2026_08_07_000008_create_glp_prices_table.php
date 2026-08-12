<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glp_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('glp_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_kg', 12, 4);
            $table->decimal('total_price', 15, 2);
            $table->decimal('unit_cost_per_kg', 18, 8);
            $table->date('effective_date')->index();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['glp_product_id', 'effective_date']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX glp_prices_one_current_per_product '
            .'ON glp_prices (glp_product_id) WHERE is_current = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('glp_prices');
    }
};
