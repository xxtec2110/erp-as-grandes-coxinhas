<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorization_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('change_type', 50)->index();
            $table->string('subject', 150);
            $table->jsonb('previous_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('source', 30)->default('web');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_audits');
    }
};
