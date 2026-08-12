<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('document_type', 30);
            $t->string('document_number')->nullable();
            $t->date('issue_date');
            $t->date('due_date')->nullable();
            $t->decimal('total_amount', 18, 2);
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('agent_attachment_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('source', 30)->default('web');
            $t->string('idempotency_key', 150)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['supplier_id', 'document_number', 'issue_date'], 'purchase_document_business_key');
        });
        Schema::create('purchase_document_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->decimal('quantity', 18, 6);
            $t->string('unit', 10);
            $t->decimal('unit_price', 18, 4);
            $t->decimal('total_price', 18, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_items');
        Schema::dropIfExists('purchase_documents');
    }
};
