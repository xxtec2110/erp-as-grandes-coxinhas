<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->decimal('quantity_delta', 18, 6);
            $table->date('operation_date')->index();
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['ingredient_id', 'location_id', 'operation_date']);
        });

        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->string('receipt_status', 30)->default('pending')->index();
            $table->date('received_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['receipt_status', 'received_date', 'received_at']);
        });
        Schema::dropIfExists('ingredient_stock_movements');
    }
};
