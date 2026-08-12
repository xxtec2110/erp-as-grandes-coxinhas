<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('display_name')->nullable();
            $table->string('status', 20)->default('approved')->index();
            $table->boolean('menu_enabled')->default(true);
            $table->boolean('structured_commands_allowed')->default(true);
            $table->boolean('free_chat_allowed')->default(false);
            $table->boolean('voice_allowed')->default(false);
            $table->boolean('image_allowed')->default(false);
            $table->boolean('document_allowed')->default(false);
            $table->boolean('reports_allowed')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
        });
        Schema::table('agent_events', function (Blueprint $table): void {
            $table->foreignId('user_external_identity_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('status', 30)->nullable()->index();
            $table->string('error_code', 60)->nullable()->index();
            $table->unsignedInteger('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_external_identity_id');
            $table->dropColumn(['status', 'error_code', 'duration_ms']);
        });
        Schema::table('user_external_identities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['display_name', 'status', 'menu_enabled', 'structured_commands_allowed', 'free_chat_allowed', 'voice_allowed', 'image_allowed', 'document_allowed', 'reports_allowed', 'approved_at', 'last_contact_at']);
        });
    }
};
