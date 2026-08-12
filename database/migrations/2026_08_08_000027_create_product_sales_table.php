<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('total_amount', 18, 2);
            $table->date('operation_date')->index();
            $table->string('source', 30)->default('web')->index();
            $table->string('idempotency_key', 150)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'operation_date']);
            $table->index(['product_id', 'operation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sales');
    }
};
