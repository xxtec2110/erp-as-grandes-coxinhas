<?php

namespace App\Services;

use App\Mail\AgentCostAlertMail;
use App\Models\AgentCostSetting;
use App\Models\AgentUsageCost;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class AgentCostService
{
    public function settings(): AgentCostSetting
    {
        return AgentCostSetting::query()->firstOrCreate([], [
            'monthly_budget' => config('agent_costs.monthly_budget', '300'),
            'warning_threshold' => config('agent_costs.warning', '200'),
            'saving_threshold' => config('agent_costs.saving', '250'),
            'critical_threshold' => config('agent_costs.critical', '280'),
            'monthly_host_cost' => '0',
            'usd_brl_rate' => filled(config('agent_costs.usd_brl_rate')) ? config('agent_costs.usd_brl_rate') : null,
            'model_rates' => config('agent_costs.model_rates', []),
            'automatic_saving_mode' => true,
        ])->refresh();
    }

    public function record(string $provider, string $type, string $key, ?User $user = null, array $metrics = []): AgentUsageCost
    {
        $estimate = $this->estimate($this->settings(), $provider, $type, $metrics);
        $usage = AgentUsageCost::query()->firstOrCreate(['idempotency_key' => $key], [
            'user_id' => $user?->id,
            'location_id' => $metrics['location_id'] ?? null,
            'provider' => $provider,
            'usage_type' => $type,
            'model' => $metrics['model'] ?? null,
            'input_tokens' => $metrics['input_tokens'] ?? null,
            'cached_input_tokens' => $metrics['cached_input_tokens'] ?? null,
            'output_tokens' => $metrics['output_tokens'] ?? null,
            'duration_ms' => $metrics['duration_ms'] ?? null,
            'quantity' => $metrics['quantity'] ?? 1,
            'category' => $metrics['category'] ?? null,
            'billable' => $metrics['billable'] ?? null,
            'estimated_cost' => $estimate['cost_brl'],
            'cost_usd' => $estimate['cost_usd'],
            'fx_rate' => $estimate['fx_rate'],
            'cost_brl' => $estimate['cost_brl'],
            'cost_estimation_status' => $estimate['status'],
            'pricing_version' => $estimate['pricing_version'],
            'pricing_date' => $estimate['pricing_date'],
            'operation_type' => $metrics['operation_type'] ?? null,
            'operation_id' => $metrics['operation_id'] ?? null,
        ]);
        if ($usage->wasRecentlyCreated) {
            $this->alertIfNeeded();
        }

        return $usage;
    }

    public function summary(): array
    {
        $start = now()->startOfMonth();
        $usage = AgentUsageCost::query()->where('created_at', '>=', $start);
        $setting = $this->settings();
        $groups = (clone $usage)->selectRaw('usage_type, SUM(COALESCE(cost_brl, estimated_cost, 0)) as total')->groupBy('usage_type')->pluck('total', 'usage_type');
        $host = BigDecimal::of($setting->monthly_host_cost);
        $meta = BigDecimal::of((string) collect($groups)->filter(fn ($value, $key) => str_starts_with($key, 'meta_'))->sum());
        $text = BigDecimal::of((string) ($groups['ai_text'] ?? 0));
        $vision = BigDecimal::of((string) ($groups['ai_vision'] ?? 0));
        $audio = BigDecimal::of((string) ($groups['ai_audio'] ?? 0));
        $total = $host->plus($meta)->plus($text)->plus($vision)->plus($audio);

        return [
            'host' => (string) $host,
            'meta' => (string) $meta,
            'text' => (string) $text,
            'vision' => (string) $vision,
            'audio' => (string) $audio,
            'total' => (string) $total,
            'budget' => $setting->monthly_budget,
            'pricing_unavailable' => (clone $usage)->whereIn('cost_estimation_status', ['pricing_missing', 'fx_missing'])->count(),
            'level' => $this->level((string) $total, $setting),
            'saving_mode' => $setting->automatic_saving_mode && $total->isGreaterThanOrEqualTo($setting->saving_threshold),
        ];
    }

    public function byUser(): Collection
    {
        return AgentUsageCost::query()->with('user')->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('user_id, usage_type, SUM(COALESCE(cost_brl, estimated_cost, 0)) as total')
            ->groupBy('user_id', 'usage_type')->get()
            ->groupBy(fn ($item) => $item->user?->name ?? 'Não identificado');
    }

    public function monthlyOpenAiSpendBrl(): string
    {
        return (string) AgentUsageCost::query()->where('provider', 'openai')
            ->where('created_at', '>=', now()->startOfMonth())->sum('cost_brl');
    }

    public function openAiPricingReady(string $model): bool
    {
        $setting = $this->settings();

        return isset(($setting->model_rates ?? [])[$model]) && filled($setting->usd_brl_rate);
    }

    public function recalculate(AgentUsageCost $usage): AgentUsageCost
    {
        $estimate = $this->estimate($this->settings(), $usage->provider, $usage->usage_type, $usage->only([
            'model', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'quantity', 'category',
        ]));
        $usage->update([
            'estimated_cost' => $estimate['cost_brl'],
            'cost_usd' => $estimate['cost_usd'],
            'fx_rate' => $estimate['fx_rate'],
            'cost_brl' => $estimate['cost_brl'],
            'cost_estimation_status' => $estimate['status'],
            'pricing_version' => $estimate['pricing_version'],
            'pricing_date' => $estimate['pricing_date'],
        ]);

        return $usage->refresh();
    }

    private function estimate(AgentCostSetting $setting, string $provider, string $type, array $metrics): array
    {
        if (array_key_exists('estimated_cost', $metrics)) {
            return $this->estimateResult(null, null, (string) $metrics['estimated_cost'], 'manual');
        }
        if ($provider === 'openai') {
            return $this->estimateOpenAi($setting, $metrics);
        }

        $rates = $provider === 'meta' ? ($setting->meta_rates ?? []) : [];
        $rate = $rates[$metrics['category'] ?? $type] ?? null;
        if ($rate === null) {
            return $this->estimateResult(null, null, null, 'pricing_missing');
        }

        $cost = (string) BigDecimal::of((string) $rate)->multipliedBy((string) ($metrics['quantity'] ?? 1));

        return $this->estimateResult(null, null, $cost, 'available');
    }

    private function estimateOpenAi(AgentCostSetting $setting, array $metrics): array
    {
        $pricing = ($setting->model_rates ?? [])[$metrics['model'] ?? ''] ?? null;
        if (! is_array($pricing) || ! isset($pricing['input_per_million_usd'], $pricing['cached_input_per_million_usd'], $pricing['output_per_million_usd'])) {
            return $this->estimateResult(null, null, null, 'pricing_missing');
        }

        $input = max(0, (int) ($metrics['input_tokens'] ?? 0));
        $cached = min($input, max(0, (int) ($metrics['cached_input_tokens'] ?? 0)));
        $output = max(0, (int) ($metrics['output_tokens'] ?? 0));
        $costUsd = BigDecimal::of((string) ($input - $cached))->multipliedBy((string) $pricing['input_per_million_usd'])
            ->plus(BigDecimal::of((string) $cached)->multipliedBy((string) $pricing['cached_input_per_million_usd']))
            ->plus(BigDecimal::of((string) $output)->multipliedBy((string) $pricing['output_per_million_usd']))
            ->dividedBy('1000000', 12, RoundingMode::HalfUp);
        $fxRate = filled($setting->usd_brl_rate) ? BigDecimal::of($setting->usd_brl_rate) : null;

        return [
            'cost_usd' => (string) $costUsd,
            'fx_rate' => $fxRate ? (string) $fxRate : null,
            'cost_brl' => $fxRate ? (string) $costUsd->multipliedBy($fxRate)->toScale(12, RoundingMode::HalfUp) : null,
            'status' => $fxRate ? 'available' : 'fx_missing',
            'pricing_version' => $pricing['version'] ?? null,
            'pricing_date' => $pricing['effective_date'] ?? null,
        ];
    }

    private function estimateResult(?string $usd, ?string $fx, ?string $brl, string $status): array
    {
        return ['cost_usd' => $usd, 'fx_rate' => $fx, 'cost_brl' => $brl, 'status' => $status, 'pricing_version' => null, 'pricing_date' => null];
    }

    private function level(string $total, AgentCostSetting $setting): string
    {
        $value = BigDecimal::of($total);
        if ($value->isGreaterThanOrEqualTo($setting->critical_threshold)) {
            return 'critical';
        }
        if ($value->isGreaterThanOrEqualTo($setting->saving_threshold)) {
            return 'saving';
        }
        if ($value->isGreaterThanOrEqualTo($setting->warning_threshold)) {
            return 'warning';
        }

        return 'normal';
    }

    private function alertIfNeeded(): void
    {
        $summary = $this->summary();
        $setting = $this->settings();
        if ($summary['level'] === 'normal') {
            if ($setting->last_alert_level !== null) {
                $setting->update(['last_alert_level' => null, 'cost_alerted_at' => null]);
            }

            return;
        }
        if ($setting->last_alert_level === $summary['level']) {
            return;
        }
        $email = config('whatsapp.alert_email');
        if (is_string($email) && $email !== '') {
            Mail::to($email)->queue(new AgentCostAlertMail($summary['level'], $summary['total']));
        }
        $setting->update(['last_alert_level' => $summary['level'], 'cost_alerted_at' => now()]);
    }
}
