<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_audits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 30);
            $t->string('action', 60)->index();
            $t->string('auditable_type');
            $t->unsignedBigInteger('auditable_id');
            $t->jsonb('previous_value')->nullable();
            $t->jsonb('new_value')->nullable();
            $t->string('idempotency_key', 150)->nullable()->index();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audits');
    }
};
