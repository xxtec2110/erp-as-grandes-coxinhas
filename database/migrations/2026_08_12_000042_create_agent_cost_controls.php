<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_cost_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('monthly_budget', 18, 6)->default(300);
            $table->decimal('warning_threshold', 18, 6)->default(200);
            $table->decimal('saving_threshold', 18, 6)->default(250);
            $table->decimal('critical_threshold', 18, 6)->default(280);
            $table->decimal('monthly_host_cost', 18, 6)->default(0);
            $table->boolean('automatic_saving_mode')->default(true);
            $table->string('meta_currency', 3)->default('BRL');
            $table->jsonb('meta_rates')->nullable();
            $table->jsonb('model_rates')->nullable();
            $table->timestamp('cost_alerted_at')->nullable();
            $table->string('last_alert_level', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('agent_usage_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('usage_type', 40)->index();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('category')->nullable();
            $table->boolean('billable')->nullable();
            $table->decimal('estimated_cost', 18, 6)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('operation_type')->nullable();
            $table->string('operation_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['created_at', 'provider', 'usage_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_usage_costs');
        Schema::dropIfExists('agent_cost_settings');
    }
};
