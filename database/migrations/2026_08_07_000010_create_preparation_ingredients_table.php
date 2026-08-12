<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->string('unit', 3);
            $table->timestamps();

            $table->unique(['preparation_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_ingredients');
    }
};
