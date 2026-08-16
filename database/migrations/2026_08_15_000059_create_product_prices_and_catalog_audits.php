<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->index();
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 18, 4);
            $table->date('effective_date')->index();
            $table->boolean('is_current')->default(false);
            $table->string('source', 40)->default('web');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestampsTz();

            $table->index(['product_id', 'effective_date']);
        });
        DB::statement('CREATE UNIQUE INDEX product_prices_one_current_per_product ON product_prices (product_id) WHERE is_current = true');

        Schema::create('catalog_admin_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30);
            $table->string('tool_name', 100);
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('status', 30)->default('success');
            $table->text('error_message')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();

            $table->index(['entity_type', 'entity_id']);
        });

        DB::table('product_recipes')
            ->whereNotNull('selling_price')
            ->orderBy('id')
            ->each(function (object $recipe): void {
                DB::table('product_prices')->insert([
                    'product_id' => $recipe->product_id,
                    'price' => $recipe->selling_price,
                    'effective_date' => now()->toDateString(),
                    'is_current' => true,
                    'source' => 'legacy_recipe',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_admin_audits');
        Schema::dropIfExists('product_prices');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
