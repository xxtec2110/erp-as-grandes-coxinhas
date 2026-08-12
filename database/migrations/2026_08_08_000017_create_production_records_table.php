<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 18, 6);
            $table->decimal('actual_quantity', 18, 6)->nullable();
            $table->date('operation_date')->index();
            $table->string('status', 30)->index();
            $table->string('idempotency_key', 150)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'operation_date', 'status']);
            $table->index(['product_id', 'operation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_records');
    }
};
