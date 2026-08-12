<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_attachments', function (Blueprint $t): void {
            $t->id();
            $t->string('source', 30);
            $t->string('external_id')->nullable();
            $t->string('content_hash', 64)->nullable()->unique();
            $t->string('disk')->nullable();
            $t->string('path')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('pending_agent_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('tool_name');
            $t->string('status', 30)->default('pending')->index();
            $t->jsonb('payload');
            $t->jsonb('missing_fields')->nullable();
            $t->string('idempotency_key', 150)->unique();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_agent_actions');
        Schema::dropIfExists('agent_attachments');
    }
};
