<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->date('production_date')->index();
            $table->string('status', 30)->default('planned')->index();
            $table->string('source', 30)->default('web');
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'production_date', 'status']);
        });
        Schema::create('production_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 18, 6);
            $table->decimal('produced_quantity', 18, 6)->nullable();
            $table->jsonb('recipe_snapshot');
            $table->decimal('unit_cost_snapshot', 18, 8);
            $table->decimal('total_cost_snapshot', 18, 8)->nullable();
            $table->string('status', 30)->default('planned');
            $table->timestamps();
        });
        Schema::create('production_order_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->string('source', 30);
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
        Schema::table('ingredient_stock_movements', function (Blueprint $table): void {
            $table->string('source', 30)->default('web');
            $table->decimal('unit_cost_snapshot', 18, 8)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('ingredient_stock_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropColumn(['source', 'unit_cost_snapshot', 'metadata']);
        });
        Schema::dropIfExists('production_order_audits');
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
    }
};
