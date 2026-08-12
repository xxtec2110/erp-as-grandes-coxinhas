<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('instance');
            $table->string('status', 30)->default('disconnected')->index();
            $table->text('qr_code')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_disconnected_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('disconnect_alerted_at')->nullable();
            $table->timestamp('reconnect_alerted_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'instance']);
        });

        Schema::create('whatsapp_inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('instance');
            $table->string('external_message_id');
            $table->string('external_user_id');
            $table->foreignId('agent_conversation_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_type', 30);
            $table->timestamp('original_timestamp')->nullable()->index();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'instance', 'external_message_id'], 'whatsapp_inbound_provider_message_unique');
            $table->index(['external_user_id', 'original_timestamp']);
        });

        Schema::table('agent_attachments', function (Blueprint $table): void {
            $table->foreignId('whatsapp_inbound_message_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('whatsapp_inbound_message_id'));
        Schema::dropIfExists('whatsapp_inbound_messages');
        Schema::dropIfExists('whatsapp_connections');
    }
};
