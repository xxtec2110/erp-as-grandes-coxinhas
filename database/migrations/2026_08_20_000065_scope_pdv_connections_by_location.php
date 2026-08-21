<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdv_connections', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->timestampTz('last_attempt_at')->nullable()->after('encrypted_credentials');
            $table->string('last_error_code', 80)->nullable()->after('last_failure_at');
            $table->text('last_error_message')->nullable()->after('last_error_code');
            $table->unique(['provider', 'location_id'], 'pdv_connection_provider_location_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pdv_connections', function (Blueprint $table): void {
            $table->dropUnique('pdv_connection_provider_location_unique');
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['last_attempt_at', 'last_error_code', 'last_error_message']);
        });
    }
};
