<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('final_weight_grams', 18, 6)->nullable();
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->decimal('technical_loss_percentage', 9, 6)->default(0);
            $table->decimal('packaging_cost', 18, 6)->default(0);
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 10);
            $table->timestamps();
        });
        Schema::create('product_recipe_preparations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preparation_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipe_preparations');
        Schema::dropIfExists('product_recipe_ingredients');
        Schema::dropIfExists('product_recipes');
    }
};
