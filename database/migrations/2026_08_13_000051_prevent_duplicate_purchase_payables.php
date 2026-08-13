<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', fn (Blueprint $table) => $table->unique('purchase_document_id', 'payables_purchase_document_unique'));
    }

    public function down(): void
    {
        Schema::table('payables', fn (Blueprint $table) => $table->dropUnique('payables_purchase_document_unique'));
    }
};
