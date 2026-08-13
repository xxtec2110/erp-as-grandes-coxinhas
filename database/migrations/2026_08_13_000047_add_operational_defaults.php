<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('default_location_id')->nullable()->after('all_locations_access')->constrained('locations')->nullOnDelete();
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->decimal('daily_sales_target', 18, 6)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_location_id'));
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn('daily_sales_target'));
    }
};
