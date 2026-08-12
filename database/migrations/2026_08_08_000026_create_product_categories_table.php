<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_category_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('product_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_category_id');
        });
        Schema::dropIfExists('product_categories');
    }
};
