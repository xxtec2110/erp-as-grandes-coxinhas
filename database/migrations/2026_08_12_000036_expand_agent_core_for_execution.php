<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30)->default('internal');
            $table->string('external_conversation_id')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->jsonb('context')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->jsonb('structured_payload')->nullable();
            $table->string('external_message_id')->nullable()->unique();
            $table->timestamps();
        });
        Schema::table('pending_agent_actions', function (Blueprint $table): void {
            $table->foreignId('agent_conversation_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->jsonb('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_agent_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('agent_conversation_id');
            $table->dropColumn(['result', 'failure_reason', 'executed_at', 'cancelled_at']);
        });
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
    }
};
