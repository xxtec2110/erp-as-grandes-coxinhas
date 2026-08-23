<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_fees', function (Blueprint $table): void {
            $table->foreignId('card_brand_id')->nullable()->change();
        });

        Schema::table('payment_fee_audits', function (Blueprint $table): void {
            $table->foreignId('card_brand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_fee_audits', function (Blueprint $table): void {
            $table->foreignId('card_brand_id')->nullable(false)->change();
        });

        Schema::table('payment_fees', function (Blueprint $table): void {
            $table->foreignId('card_brand_id')->nullable(false)->change();
        });
    }
};
