<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sale_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('pdv_connection_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('pdv_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('operation_date')->index();
            $table->string('entry_source', 30)->default('pdv')->index();
            $table->string('external_reference')->nullable();
            $table->string('status', 20)->default('completed')->index();
            $table->decimal('subtotal_snapshot', 18, 2);
            $table->decimal('discount_total_snapshot', 18, 2)->default(0);
            $table->decimal('service_total_snapshot', 18, 2)->default(0);
            $table->decimal('delivery_total_snapshot', 18, 2)->default(0);
            $table->decimal('total_amount_snapshot', 18, 2);
            $table->decimal('paid_total_snapshot', 18, 2)->nullable();
            $table->decimal('change_total_snapshot', 18, 2)->nullable();
            $table->string('source_hash_snapshot', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('imported_at');
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->string('idempotency_key', 150)->unique();
            $table->timestampsTz();

            $table->index(['location_id', 'operation_date'], 'product_sale_orders_location_date_index');
            $table->index(['pdv_connection_id', 'external_reference'], 'product_sale_orders_external_index');
        });

        Schema::table('product_sales', function (Blueprint $table): void {
            $table->foreignId('product_sale_order_id')
                ->nullable()
                ->after('location_id')
                ->constrained('product_sale_orders')
                ->restrictOnDelete();
            $table->decimal('subtotal_amount_snapshot', 18, 2)->nullable()->after('total_amount');
            $table->decimal('discount_amount_snapshot', 18, 2)->default(0)->after('subtotal_amount_snapshot');
            $table->index(['product_sale_order_id', 'product_id'], 'product_sales_order_product_index');
        });

        DB::table('product_sales')->whereNull('subtotal_amount_snapshot')->update([
            'subtotal_amount_snapshot' => DB::raw('total_amount'),
        ]);

        Schema::create('product_sale_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_sale_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('pdv_order_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('external_reference')->nullable();
            $table->string('payment_method', 30)->index();
            $table->string('status', 20)->default('completed')->index();
            $table->decimal('external_amount_snapshot', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2);
            $table->foreignId('acquirer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('card_brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->decimal('fee_percentage_snapshot', 9, 6)->default(0);
            $table->decimal('fixed_fee_snapshot', 18, 4)->default(0);
            $table->foreignId('payment_fee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('product_sale_payments')->restrictOnDelete();
            $table->string('idempotency_key', 150)->unique();
            $table->timestampsTz();

            $table->index(['product_sale_order_id', 'payment_method'], 'product_sale_payments_order_method_index');
        });

        Schema::create('product_sale_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_sale_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_sale_id')->constrained()->restrictOnDelete();
            $table->decimal('gross_allocated', 18, 2);
            $table->decimal('revenue_allocated', 18, 2);
            $table->decimal('fee_allocated', 18, 2);
            $table->decimal('net_allocated', 18, 2);
            $table->timestampsTz();

            $table->unique(['product_sale_payment_id', 'product_sale_id'], 'product_sale_payment_allocation_unique');
            $table->index('product_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sale_payment_allocations');
        Schema::dropIfExists('product_sale_payments');

        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropIndex('product_sales_order_product_index');
            $table->dropConstrainedForeignId('product_sale_order_id');
            $table->dropColumn(['subtotal_amount_snapshot', 'discount_amount_snapshot']);
        });

        Schema::dropIfExists('product_sale_orders');
    }
};
