<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->string('series', 30)->nullable();
            $table->string('access_key', 80)->nullable()->index();
            $table->string('currency', 3)->default('BRL');
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('other_charges_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->string('source_type', 30)->default('purchase')->index();
            $table->string('document_status', 30)->default('confirmed')->index();
            $table->string('identity_hash', 64)->nullable()->unique();
        });

        Schema::table('purchase_document_items', function (Blueprint $table): void {
            $table->string('external_code', 100)->nullable()->index();
            $table->decimal('package_quantity', 18, 6)->nullable();
            $table->decimal('package_size', 18, 6)->nullable();
            $table->string('package_unit', 10)->nullable();
            $table->decimal('unit_price_original', 18, 6)->nullable();
            $table->decimal('package_price', 18, 4)->nullable();
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('other_charges_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->decimal('normalized_quantity', 18, 6)->nullable();
            $table->string('normalized_unit', 10)->nullable();
            $table->decimal('normalized_unit_cost', 18, 8)->nullable();
        });

        Schema::table('ingredient_prices', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->unique()->constrained('purchase_document_items')->nullOnDelete();
            $table->timestampTz('effective_at')->nullable()->index();
            $table->date('purchase_date')->nullable()->index();
            $table->timestampTz('received_at')->nullable();
            $table->string('source_type', 30)->default('manual_price')->index();
            $table->decimal('package_quantity', 18, 6)->nullable();
            $table->decimal('package_size', 18, 6)->nullable();
            $table->string('package_unit', 10)->nullable();
            $table->decimal('unit_price_original', 18, 6)->nullable();
            $table->decimal('package_price', 18, 4)->nullable();
            $table->decimal('gross_total', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('other_charges_amount', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->nullable();
            $table->string('normalized_unit', 10)->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_channel', 30)->default('web');
        });
        DB::statement('DROP INDEX IF EXISTS ingredient_prices_one_current_per_ingredient');
        DB::statement('CREATE UNIQUE INDEX ingredient_prices_one_current_per_ingredient ON ingredient_prices (ingredient_id) WHERE is_current = true');

        Schema::create('supplier_ingredient_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->string('external_code', 100)->nullable();
            $table->string('external_description');
            $table->string('normalized_description')->index();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['supplier_id', 'normalized_description'], 'supplier_ingredient_description_unique');
            $table->unique(['supplier_id', 'external_code'], 'supplier_ingredient_code_unique');
        });

        Schema::create('purchase_document_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('uploaded')->index();
            $table->string('document_type', 40)->nullable()->index();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name_extracted')->nullable();
            $table->string('supplier_document_extracted', 30)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->string('series', 30)->nullable();
            $table->string('access_key', 80)->nullable();
            $table->date('issue_date')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('other_charges_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->decimal('confidence', 7, 6)->nullable();
            $table->jsonb('field_confidences')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->jsonb('missing_fields')->nullable();
            $table->jsonb('ambiguous_fields')->nullable();
            $table->jsonb('interpretation')->nullable();
            $table->string('identity_hash', 64)->nullable()->index();
            $table->string('idempotency_key', 190)->unique();
            $table->foreignId('confirmed_purchase_document_id')->nullable()->constrained('purchase_documents')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('purchase_document_import_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_document_import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_attachment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('page_order')->default(1);
            $table->timestampsTz();
            $table->unique(['purchase_document_import_id', 'agent_attachment_id'], 'purchase_import_attachment_unique');
        });

        Schema::create('purchase_document_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_document_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('external_code', 100)->nullable();
            $table->string('description');
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mapping_status', 30)->default('unresolved')->index();
            $table->decimal('quantity', 18, 6)->nullable();
            $table->string('unit', 10)->nullable();
            $table->decimal('package_quantity', 18, 6)->nullable();
            $table->decimal('package_size', 18, 6)->nullable();
            $table->string('package_unit', 10)->nullable();
            $table->decimal('unit_price_original', 18, 6)->nullable();
            $table->decimal('package_price', 18, 4)->nullable();
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('other_charges_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->nullable();
            $table->decimal('normalized_quantity', 18, 6)->nullable();
            $table->string('normalized_unit', 10)->nullable();
            $table->decimal('normalized_unit_cost', 18, 8)->nullable();
            $table->decimal('confidence', 7, 6)->nullable();
            $table->jsonb('warnings')->nullable();
            $table->timestampsTz();
            $table->unique(['purchase_document_import_id', 'line_number'], 'purchase_import_line_unique');
        });

        Schema::create('product_cost_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('effective_at')->index();
            $table->decimal('unit_cost', 18, 8);
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->decimal('gross_profit', 18, 4)->nullable();
            $table->decimal('gross_margin_percentage', 9, 4)->nullable();
            $table->string('recipe_signature', 64);
            $table->string('cost_method', 40)->default('replacement_cost');
            $table->string('source_type', 40)->default('calculated');
            $table->string('source_id')->nullable();
            $table->jsonb('components');
            $table->jsonb('context')->nullable();
            $table->string('idempotency_key', 190)->unique();
            $table->timestampsTz();
            $table->index(['product_id', 'effective_at']);
        });

        Schema::table('product_sales', function (Blueprint $table): void {
            $table->foreignId('product_cost_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost_snapshot', 18, 8)->nullable();
            $table->decimal('total_cost_snapshot', 18, 2)->nullable();
            $table->decimal('gross_profit_snapshot', 18, 2)->nullable();
            $table->decimal('gross_margin_percentage_snapshot', 9, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_cost_snapshot_id');
            $table->dropColumn(['unit_cost_snapshot', 'total_cost_snapshot', 'gross_profit_snapshot', 'gross_margin_percentage_snapshot']);
        });
        Schema::dropIfExists('product_cost_snapshots');
        Schema::dropIfExists('purchase_document_import_items');
        Schema::dropIfExists('purchase_document_import_attachments');
        Schema::dropIfExists('purchase_document_imports');
        Schema::dropIfExists('supplier_ingredient_mappings');

        Schema::table('ingredient_prices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('purchase_document_id');
            $table->dropConstrainedForeignId('purchase_item_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['effective_at', 'purchase_date', 'received_at', 'source_type', 'package_quantity', 'package_size', 'package_unit', 'unit_price_original', 'package_price', 'gross_total', 'discount_amount', 'freight_amount', 'other_charges_amount', 'net_total', 'normalized_unit', 'currency', 'source_channel']);
        });
        DB::statement('DROP INDEX IF EXISTS ingredient_prices_one_current_per_ingredient');
        DB::statement('CREATE UNIQUE INDEX ingredient_prices_one_current_per_ingredient ON ingredient_prices (ingredient_id) WHERE is_current = true');
        Schema::table('purchase_document_items', function (Blueprint $table): void {
            $table->dropColumn(['external_code', 'package_quantity', 'package_size', 'package_unit', 'unit_price_original', 'package_price', 'gross_amount', 'discount_amount', 'freight_amount', 'other_charges_amount', 'net_amount', 'normalized_quantity', 'normalized_unit', 'normalized_unit_cost']);
        });
        Schema::table('purchase_documents', function (Blueprint $table): void {
            $table->dropColumn(['series', 'access_key', 'currency', 'gross_amount', 'discount_amount', 'freight_amount', 'other_charges_amount', 'net_amount', 'source_type', 'document_status', 'identity_hash']);
        });
    }
};
