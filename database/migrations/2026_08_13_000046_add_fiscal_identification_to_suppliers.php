<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('document_type', 10)->nullable()->after('name');
            $table->string('document_number', 20)->nullable()->after('document_type');
            $table->index(['document_type', 'document_number']);
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_active_fiscal_document_unique ON suppliers (document_type, document_number) WHERE active = true AND document_number IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS suppliers_active_fiscal_document_unique');
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['document_type', 'document_number']);
            $table->dropColumn(['document_type', 'document_number']);
        });
    }
};
