<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->boolean('respond_enabled')->default(true)->after('active');
            $table->dropUnique('user_external_identities_channel_external_user_id_unique');
            $table->index(['channel', 'external_user_id'], 'external_identity_channel_identifier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->dropIndex('external_identity_channel_identifier_idx');
            $table->unique(['channel', 'external_user_id']);
            $table->dropColumn('respond_enabled');
        });
    }
};
