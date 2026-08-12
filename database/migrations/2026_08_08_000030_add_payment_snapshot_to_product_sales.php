<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->foreignId('acquirer_id')->nullable()->after('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('card_brand_id')->nullable()->after('acquirer_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 30)->default('cash')->after('card_brand_id')->index();
            $table->unsignedSmallInteger('installments')->nullable()->after('payment_method');
            $table->decimal('gross_amount', 18, 2)->nullable()->after('total_amount');
            $table->decimal('fee_percentage_snapshot', 9, 6)->default(0)->after('gross_amount');
            $table->decimal('fixed_fee_snapshot', 18, 4)->default(0)->after('fee_percentage_snapshot');
            $table->decimal('fee_amount_snapshot', 18, 2)->default(0)->after('fixed_fee_snapshot');
            $table->decimal('net_amount', 18, 2)->nullable()->after('fee_amount_snapshot');
            $table->foreignId('payment_fee_id')->nullable()->after('net_amount')->constrained()->nullOnDelete();
        });
        DB::table('product_sales')->update(['gross_amount' => DB::raw('total_amount'), 'net_amount' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_fee_id');
            $table->dropConstrainedForeignId('card_brand_id');
            $table->dropConstrainedForeignId('acquirer_id');
            $table->dropColumn(['payment_method', 'installments', 'gross_amount', 'fee_percentage_snapshot', 'fixed_fee_snapshot', 'fee_amount_snapshot', 'net_amount']);
        });
    }
};
