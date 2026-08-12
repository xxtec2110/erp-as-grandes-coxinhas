<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('minimum_quantity', 18, 6)->nullable();
            $table->decimal('target_quantity', 18, 6);
            $table->unsignedSmallInteger('production_priority')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
            $table->index(['location_id', 'active', 'production_priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_policies');
    }
};
