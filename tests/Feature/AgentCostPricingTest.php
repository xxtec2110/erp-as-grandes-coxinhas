<?php

namespace Tests\Feature;

use App\Models\AgentUsageCost;
use App\Models\User;
use App\Services\AgentCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentCostPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_gpt_4_1_mini_cost_uses_input_output_and_precise_brl_conversion(): void
    {
        $costs = app(AgentCostService::class);
        $costs->settings()->update(['usd_brl_rate' => '5.00000000']);

        $usage = $costs->record('openai', 'ai_text', 'priced-call', metrics: [
            'model' => 'gpt-4.1-mini', 'input_tokens' => 979, 'output_tokens' => 64,
        ]);

        $this->assertSame('0.000494000000', $usage->cost_usd);
        $this->assertSame('0.002470000000', $usage->cost_brl);
        $this->assertSame('available', $usage->cost_estimation_status);
        $this->assertSame('2026-08-15', $usage->pricing_date->toDateString());
        $this->assertNotSame('0.000000000000', $usage->cost_brl);
    }

    public function test_cached_input_uses_its_own_rate(): void
    {
        $costs = app(AgentCostService::class);
        $costs->settings()->update(['usd_brl_rate' => '5']);
        $usage = $costs->record('openai', 'ai_text', 'cached-call', metrics: [
            'model' => 'gpt-4.1-mini', 'input_tokens' => 1000, 'cached_input_tokens' => 400, 'output_tokens' => 100,
        ]);

        $this->assertSame('0.000440000000', $usage->cost_usd);
        $this->assertSame(400, $usage->cached_input_tokens);
    }

    public function test_missing_fx_preserves_usd_cost_and_marks_estimation_unavailable(): void
    {
        $usage = app(AgentCostService::class)->record('openai', 'ai_text', 'without-fx', metrics: [
            'model' => 'gpt-4.1-mini', 'input_tokens' => 10, 'output_tokens' => 1,
        ]);

        $this->assertSame('0.000005600000', $usage->cost_usd);
        $this->assertNull($usage->cost_brl);
        $this->assertSame('fx_missing', $usage->cost_estimation_status);
    }

    public function test_unknown_model_is_not_silently_recorded_as_free(): void
    {
        $usage = app(AgentCostService::class)->record('openai', 'ai_text', 'unknown-model', metrics: [
            'model' => 'unknown', 'input_tokens' => 10, 'output_tokens' => 1,
        ]);

        $this->assertNull($usage->cost_usd);
        $this->assertNull($usage->estimated_cost);
        $this->assertSame('pricing_missing', $usage->cost_estimation_status);
    }

    public function test_future_rate_change_is_applied_by_recalculation_without_changing_tokens(): void
    {
        $costs = app(AgentCostService::class);
        $settings = $costs->settings();
        $settings->update(['usd_brl_rate' => '5']);
        $usage = $costs->record('openai', 'ai_text', 'repriced', metrics: [
            'model' => 'gpt-4.1-mini', 'input_tokens' => 1000, 'output_tokens' => 0,
        ]);
        $rates = $settings->fresh()->model_rates;
        $rates['gpt-4.1-mini']['input_per_million_usd'] = '0.80';
        $settings->update(['model_rates' => $rates]);

        $recalculated = $costs->recalculate($usage);

        $this->assertSame(1000, $recalculated->input_tokens);
        $this->assertSame('0.000800000000', $recalculated->cost_usd);
    }

    public function test_live_budget_consumes_precise_brl_value(): void
    {
        config()->set(['ai.live_test.enabled' => true, 'ai.openai.enabled' => true, 'ai.openai.api_key' => 'fake-only', 'ai.models.text' => 'gpt-4.1-mini', 'ai.live_test.budget_brl' => '0.002']);
        $costs = app(AgentCostService::class);
        $costs->settings()->update(['usd_brl_rate' => '5']);
        $costs->record('openai', 'ai_text', 'budget-call', metrics: [
            'model' => 'gpt-4.1-mini', 'input_tokens' => 979, 'output_tokens' => 64,
        ]);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $response = $this->actingAs($admin)->get(route('agent.simulator'));

        $response->assertOk();
        $this->assertFalse($response->viewData('liveAvailable'));
        $this->assertSame('0.00247', $costs->monthlyOpenAiSpendBrl());
    }

    public function test_record_remains_idempotent_with_precise_cost(): void
    {
        $costs = app(AgentCostService::class);
        $costs->settings()->update(['usd_brl_rate' => '5']);
        $metrics = ['model' => 'gpt-4.1-mini', 'input_tokens' => 10, 'output_tokens' => 1];
        $costs->record('openai', 'ai_text', 'same-key', metrics: $metrics);
        $costs->record('openai', 'ai_text', 'same-key', metrics: [...$metrics, 'input_tokens' => 999]);

        $this->assertDatabaseCount('agent_usage_costs', 1);
        $this->assertSame(10, AgentUsageCost::query()->firstOrFail()->input_tokens);
    }
}
