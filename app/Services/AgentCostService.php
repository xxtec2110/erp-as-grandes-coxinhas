<?php

namespace App\Services;

use App\Mail\AgentCostAlertMail;
use App\Models\AgentCostSetting;
use App\Models\AgentUsageCost;
use App\Models\User;
use Brick\Math\BigDecimal;
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
            'automatic_saving_mode' => true,
        ])->refresh();
    }

    public function record(string $provider, string $type, string $key, ?User $user = null, array $metrics = []): AgentUsageCost
    {
        $setting = $this->settings();
        $cost = $metrics['estimated_cost'] ?? $this->estimate($setting, $provider, $type, $metrics);

        $usage = AgentUsageCost::query()->firstOrCreate(['idempotency_key' => $key], [
            'user_id' => $user?->id, 'location_id' => $metrics['location_id'] ?? null, 'provider' => $provider,
            'usage_type' => $type, 'model' => $metrics['model'] ?? null, 'input_tokens' => $metrics['input_tokens'] ?? null,
            'output_tokens' => $metrics['output_tokens'] ?? null, 'duration_ms' => $metrics['duration_ms'] ?? null,
            'quantity' => $metrics['quantity'] ?? 1, 'category' => $metrics['category'] ?? null,
            'billable' => $metrics['billable'] ?? null, 'estimated_cost' => $cost,
            'operation_type' => $metrics['operation_type'] ?? null, 'operation_id' => $metrics['operation_id'] ?? null,
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
        $groups = (clone $usage)->selectRaw('usage_type, SUM(estimated_cost) as total')->groupBy('usage_type')->pluck('total', 'usage_type');
        $host = BigDecimal::of($setting->monthly_host_cost);
        $meta = BigDecimal::of((string) collect($groups)->filter(fn ($value, $key) => str_starts_with($key, 'meta_'))->sum());
        $text = BigDecimal::of((string) ($groups['ai_text'] ?? 0));
        $vision = BigDecimal::of((string) ($groups['ai_vision'] ?? 0));
        $audio = BigDecimal::of((string) ($groups['ai_audio'] ?? 0));
        $total = $host->plus($meta)->plus($text)->plus($vision)->plus($audio);

        return ['host' => (string) $host, 'meta' => (string) $meta, 'text' => (string) $text, 'vision' => (string) $vision, 'audio' => (string) $audio, 'total' => (string) $total, 'budget' => $setting->monthly_budget, 'level' => $this->level((string) $total, $setting), 'saving_mode' => $setting->automatic_saving_mode && $total->isGreaterThanOrEqualTo($setting->saving_threshold)];
    }

    public function byUser(): Collection
    {
        return AgentUsageCost::query()->with('user')->where('created_at', '>=', now()->startOfMonth())->selectRaw('user_id, usage_type, SUM(estimated_cost) as total')->groupBy('user_id', 'usage_type')->get()->groupBy(fn ($item) => $item->user?->name ?? 'Não identificado');
    }

    private function estimate(AgentCostSetting $setting, string $provider, string $type, array $metrics): string
    {
        $rates = $provider === 'meta' ? ($setting->meta_rates ?? []) : ($setting->model_rates ?? []);
        $rate = (string) ($rates[$metrics['category'] ?? $type] ?? '0');

        return (string) BigDecimal::of($rate)->multipliedBy((string) ($metrics['quantity'] ?? 1));
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
