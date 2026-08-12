<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('external_event_id')->unique();
            $table->string('event_type', 30)->index();
            $table->string('status', 30)->default('received')->index();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient');
            $table->string('message_type', 20)->default('text');
            $table->text('body');
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_messages');
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
