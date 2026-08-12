<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_external_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('external_user_id');
            $table->boolean('active')->default(true)->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_user_id']);
        });
        Schema::create('agent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 50)->index();
            $table->string('channel', 30)->index();
            $table->string('external_message_id')->nullable()->index();
            $table->string('tool_name')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('user_external_identities');
    }
};
