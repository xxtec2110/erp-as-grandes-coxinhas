<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_cost_settings', function (Blueprint $table): void {
            $table->decimal('usd_brl_rate', 18, 8)->nullable();
        });

        Schema::table('agent_usage_costs', function (Blueprint $table): void {
            $table->decimal('estimated_cost', 24, 12)->nullable()->default(null)->change();
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('input_tokens');
            $table->decimal('cost_usd', 24, 12)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();
            $table->decimal('cost_brl', 24, 12)->nullable();
            $table->string('cost_estimation_status', 30)->default('legacy');
            $table->string('pricing_version', 40)->nullable();
            $table->date('pricing_date')->nullable();
        });

        $defaultPricing = config('agent_costs.model_rates', []);
        DB::table('agent_cost_settings')->whereNull('model_rates')->update([
            'model_rates' => json_encode($defaultPricing, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('agent_usage_costs')->whereNull('estimated_cost')->update(['estimated_cost' => 0]);
        Schema::table('agent_usage_costs', function (Blueprint $table): void {
            $table->dropColumn([
                'cached_input_tokens',
                'cost_usd',
                'fx_rate',
                'cost_brl',
                'cost_estimation_status',
                'pricing_version',
                'pricing_date',
            ]);
            $table->decimal('estimated_cost', 18, 6)->default(0)->nullable(false)->change();
        });

        Schema::table('agent_cost_settings', function (Blueprint $table): void {
            $table->dropColumn('usd_brl_rate');
        });
    }
};
