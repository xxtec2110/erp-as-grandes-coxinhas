<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('purchase_quantity', 15, 4);
            $table->string('purchase_unit', 10);
            $table->decimal('normalized_quantity', 18, 6);
            $table->decimal('price_paid', 15, 2);
            $table->decimal('base_unit_cost', 18, 8);
            $table->date('effective_date')->index();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['ingredient_id', 'effective_date']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX ingredient_prices_one_current_per_ingredient '
            .'ON ingredient_prices (ingredient_id) WHERE is_current = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_prices');
    }
};
