<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdv_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40)->index();
            $table->string('name');
            $table->string('status', 30)->default('not_configured')->index();
            $table->boolean('enabled')->default(false);
            $table->jsonb('configuration')->nullable();
            $table->text('encrypted_credentials')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->timestampTz('last_sale_imported_at')->nullable();
            $table->unsignedInteger('sync_lag_seconds')->nullable();
            $table->unsignedBigInteger('sales_imported_count')->default(0);
            $table->unsignedBigInteger('events_failed_count')->default(0);
            $table->unsignedBigInteger('events_waiting_mapping_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('pdv_location_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_location_id');
            $table->string('external_name')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->timestampsTz();
            $table->unique(['pdv_connection_id', 'external_location_id'], 'pdv_location_external_unique');
        });
        Schema::create('pdv_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_product_id');
            $table->string('external_sku')->nullable()->index();
            $table->string('external_name');
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('match_source', 30)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestampsTz();
            $table->unique(['pdv_connection_id', 'external_product_id'], 'pdv_product_external_unique');
        });
        Schema::create('pdv_payment_method_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_method_code');
            $table->string('external_name')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('acquirer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('card_brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->timestampsTz();
            $table->unique(['pdv_connection_id', 'external_method_code'], 'pdv_payment_external_unique');
        });
        Schema::create('pdv_sync_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('stream', 40)->default('sales');
            $table->jsonb('cursor')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['pdv_connection_id', 'location_id', 'stream'], 'pdv_checkpoint_scope_unique');
        });
        Schema::create('pdv_inbound_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->index();
            $table->string('external_event_id');
            $table->string('external_sale_id')->nullable()->index();
            $table->string('event_type', 50);
            $table->string('payload_hash', 64)->index();
            $table->jsonb('payload')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('external_updated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['pdv_connection_id', 'external_event_id'], 'pdv_inbound_event_unique');
            $table->index(['pdv_connection_id', 'status', 'created_at'], 'pdv_inbound_status_lookup');
        });
        Schema::create('pdv_integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdv_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pdv_inbound_event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 60)->index();
            $table->string('status', 30)->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('lag_seconds')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->foreignId('pdv_connection_id')->nullable()->after('source')->constrained()->restrictOnDelete();
            $table->string('external_sale_id')->nullable()->after('pdv_connection_id');
            $table->string('external_item_id')->nullable()->after('external_sale_id');
            $table->string('external_status', 40)->nullable();
            $table->timestampTz('external_updated_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['pdv_connection_id', 'external_sale_id', 'external_item_id'], 'product_sales_pdv_item_unique');
        });
        DB::table('pdv_connections')->insert(['provider' => 'grandchef', 'name' => 'GrandChef', 'status' => 'not_configured', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropUnique('product_sales_pdv_item_unique');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('pdv_connection_id');
            $table->dropColumn(['external_sale_id', 'external_item_id', 'external_status', 'external_updated_at', 'cancelled_at', 'cancellation_reason']);
        });
        Schema::dropIfExists('pdv_integration_events');
        Schema::dropIfExists('pdv_inbound_events');
        Schema::dropIfExists('pdv_sync_checkpoints');
        Schema::dropIfExists('pdv_payment_method_mappings');
        Schema::dropIfExists('pdv_product_mappings');
        Schema::dropIfExists('pdv_location_mappings');
        Schema::dropIfExists('pdv_connections');
    }
};
