<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_admin_audits', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->json('context')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_admin_audits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn('context');
        });
    }
};
