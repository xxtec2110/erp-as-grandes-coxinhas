<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->timestamp('last_failed_at')->nullable()->after('processed_at');
            $table->timestamp('next_retry_at')->nullable()->after('last_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->dropColumn(['last_failed_at', 'next_retry_at']);
        });
    }
};
