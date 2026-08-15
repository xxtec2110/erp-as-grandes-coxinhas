<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\AgentUsageCost;
use Carbon\CarbonImmutable;

class AgentUsageReportService
{
    public function summarize(CarbonImmutable $start, CarbonImmutable $end)
    {
        $events = AgentEvent::query()->leftJoin('users', 'users.id', '=', 'agent_events.user_id')->whereBetween('agent_events.created_at', [$start, $end])->selectRaw("COALESCE(users.name, 'Não identificado') user_name, agent_events.user_id, COUNT(*) messages, COUNT(*) FILTER (WHERE event_type='deterministic_command') deterministic_commands, COUNT(*) FILTER (WHERE event_type='ai_called') ai_calls, COUNT(*) FILTER (WHERE event_type LIKE '%error%' OR status IN ('failed','rejected')) errors")->groupBy('agent_events.user_id', 'users.name')->get()->keyBy('user_id');
        $costs = AgentUsageCost::query()->whereBetween('created_at', [$start, $end])->selectRaw('user_id, SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens, SUM(COALESCE(cost_brl, estimated_cost, 0)) total_cost')->groupBy('user_id')->get()->keyBy('user_id');

        return $events->map(function ($r, $id) use ($costs) {
            $c = $costs->get($id);
            $r->input_tokens = $c?->input_tokens ?? 0;
            $r->output_tokens = $c?->output_tokens ?? 0;
            $r->total_cost = $c?->total_cost ?? '0';

            return $r;
        })->values();
    }
}
