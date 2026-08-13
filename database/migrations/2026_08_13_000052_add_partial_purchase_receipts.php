<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_document_items', fn (Blueprint $t) => $t->decimal('received_quantity', 18, 6)->default(0));
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_document_id')->constrained()->restrictOnDelete();
            $t->date('received_date');
            $t->string('idempotency_key', 190)->unique();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 30)->default('web');
            $t->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_document_item_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity_received', 18, 6);
            $t->timestamps();
            $t->unique(['purchase_receipt_id', 'purchase_document_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::table('purchase_document_items', fn (Blueprint $t) => $t->dropColumn('received_quantity'));
    }
};
