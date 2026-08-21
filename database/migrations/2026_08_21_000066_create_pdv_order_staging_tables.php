<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdv_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('external_order_id');
            $table->string('external_code')->nullable();
            $table->string('external_status', 40);
            $table->decimal('quantity', 18, 6)->nullable();
            $table->decimal('service_total', 18, 2)->nullable();
            $table->decimal('delivery_total', 18, 2)->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount_total', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('paid_total', 18, 2)->nullable();
            $table->decimal('change_total', 18, 2)->nullable();
            $table->timestampTz('external_created_at')->nullable();
            $table->timestampTz('external_completed_at')->nullable();
            $table->timestampTz('external_updated_at')->nullable();
            $table->string('source_hash', 64);
            $table->string('latest_source_hash', 64);
            $table->unsignedSmallInteger('normalization_version')->default(1);
            $table->string('processing_state', 30)->default('staged');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('source_changed_at')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['pdv_connection_id', 'external_order_id'], 'pdv_orders_connection_external_unique');
            $table->index(['pdv_connection_id', 'external_completed_at'], 'pdv_orders_connection_completed_index');
            $table->index(['location_id', 'external_completed_at'], 'pdv_orders_location_completed_index');
            $table->index('processing_state');
        });

        Schema::create('pdv_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_order_id')->constrained()->restrictOnDelete();
            $table->string('external_item_id');
            $table->string('external_product_id')->nullable();
            $table->string('external_product_code')->nullable();
            $table->string('description');
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->decimal('subtotal', 18, 2)->nullable();
            $table->decimal('total', 18, 2);
            $table->string('external_status', 40)->nullable();
            $table->boolean('cancelled')->default(false);
            $table->boolean('present_in_latest')->default(true);
            $table->string('source_hash', 64)->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['pdv_order_id', 'external_item_id'], 'pdv_order_items_order_external_unique');
            $table->index(['pdv_order_id', 'present_in_latest'], 'pdv_order_items_current_index');
            $table->index('external_product_id');
        });

        Schema::create('pdv_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_order_id')->constrained()->restrictOnDelete();
            $table->string('external_payment_id');
            $table->string('external_form_id')->nullable();
            $table->string('external_form_description')->nullable();
            $table->string('external_type')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('external_total', 18, 2)->nullable();
            $table->decimal('fees', 18, 2)->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('external_status', 40)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->boolean('present_in_latest')->default(true);
            $table->string('source_hash', 64)->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['pdv_order_id', 'external_payment_id'], 'pdv_order_payments_order_external_unique');
            $table->index(['pdv_order_id', 'present_in_latest'], 'pdv_order_payments_current_index');
            $table->index('external_form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdv_order_payments');
        Schema::dropIfExists('pdv_order_items');
        Schema::dropIfExists('pdv_orders');
    }
};
