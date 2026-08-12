<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('description');
            $t->foreignId('purchase_document_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('finance_category_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('expected_amount', 18, 2);
            $t->date('competency_date');
            $t->date('due_date');
            $t->boolean('recurring')->default(false);
            $t->string('recurrence_rule', 30)->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 30)->default('pending')->index();
            $t->string('source', 30)->default('web');
            $t->string('idempotency_key', 150)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payable_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 18, 2);
            $t->timestamp('paid_at');
            $t->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('paid_by_name')->nullable();
            $t->string('payment_method', 30);
            $t->boolean('partner_advance')->default(false);
            $t->foreignId('agent_attachment_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('source', 30)->default('web');
            $t->string('idempotency_key', 150)->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payables');
    }
};
