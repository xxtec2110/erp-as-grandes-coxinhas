<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_policy_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_stock_policy_id')->constrained()->restrictOnDelete();
            $table->decimal('previous_minimum_quantity', 18, 6)->nullable();
            $table->decimal('new_minimum_quantity', 18, 6)->nullable();
            $table->decimal('previous_target_quantity', 18, 6)->nullable();
            $table->decimal('new_target_quantity', 18, 6);
            $table->unsignedSmallInteger('previous_production_priority')->nullable();
            $table->unsignedSmallInteger('new_production_priority');
            $table->boolean('previous_active')->nullable();
            $table->boolean('new_active');
            $table->string('channel', 30)->default('web');
            $table->string('idempotency_key', 150)->unique();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_stock_policy_id', 'created_at'], 'stock_policy_history_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_policy_histories');
    }
};
