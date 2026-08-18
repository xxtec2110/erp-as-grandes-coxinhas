<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget_key', 120);
            $table->string('visibility', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'widget_key']);
            $table->index(['widget_key', 'visibility']);
        });

        Schema::table('authorization_audits', function (Blueprint $table): void {
            $table->jsonb('context')->nullable();
            $table->string('idempotency_key', 190)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('authorization_audits', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['context', 'idempotency_key']);
        });

        Schema::dropIfExists('user_dashboard_widgets');
    }
};
