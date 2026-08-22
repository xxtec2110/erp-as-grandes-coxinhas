<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdv_connections', function (Blueprint $table): void {
            $table->timestampTz('operational_start_at')->nullable()->after('enabled')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pdv_connections', function (Blueprint $table): void {
            $table->dropColumn('operational_start_at');
        });
    }
};
