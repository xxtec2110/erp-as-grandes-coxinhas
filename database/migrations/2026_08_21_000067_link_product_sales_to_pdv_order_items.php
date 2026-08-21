<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->foreignId('pdv_order_item_id')
                ->nullable()
                ->after('pdv_connection_id')
                ->unique()
                ->constrained('pdv_order_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pdv_order_item_id');
        });
    }
};
