<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_attachments', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->string('original_name')->nullable()->after('path');
            $table->string('processing_status', 30)->default('stored')->index()->after('size');
            $table->string('retention_type', 20)->default('official')->index()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('agent_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['original_name', 'processing_status', 'retention_type']);
        });
    }
};
