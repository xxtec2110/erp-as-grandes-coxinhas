<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_fee_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30)->index();
            $table->string('attachment_id')->nullable()->index();
            $table->foreignId('acquirer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 40)->index();
            $table->jsonb('parsed_payload')->nullable();
            $table->jsonb('validation_errors')->nullable();
            $table->string('idempotency_key', 150)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_fees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acquirer_id')->constrained()->restrictOnDelete();
            $table->foreignId('card_brand_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 30)->index();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->decimal('fee_percentage', 9, 6);
            $table->decimal('fixed_fee', 18, 4)->default(0);
            $table->date('effective_from')->index();
            $table->date('effective_until')->nullable()->index();
            $table->boolean('is_current')->default(true)->index();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->string('source', 30)->default('web')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_fee_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['acquirer_id', 'card_brand_id', 'payment_method', 'installments'], 'payment_fee_lookup');
        });
        Schema::create('payment_fee_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_fee_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('acquirer_id')->constrained()->restrictOnDelete();
            $table->foreignId('card_brand_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 30);
            $table->unsignedSmallInteger('installments')->nullable();
            $table->jsonb('previous_value')->nullable();
            $table->jsonb('new_value');
            $table->string('source', 30);
            $table->foreignId('payment_fee_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_fee_audits');
        Schema::dropIfExists('payment_fees');
        Schema::dropIfExists('payment_fee_imports');
    }
};
