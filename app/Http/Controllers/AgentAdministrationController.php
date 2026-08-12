<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExternalIdentityRequest;
use App\Models\AgentConversation;
use App\Models\AgentEvent;
use App\Models\Location;
use App\Models\PendingAgentAction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Models\WhatsAppInboundMessage;
use App\Services\AgentCostService;
use App\Services\AuthorizationService;
use App\Services\ExternalIdentityService;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentAdministrationController extends Controller
{
    public function identities(): View
    {
        return view('agent.identities.index', ['identities' => UserExternalIdentity::query()->with(['user.roles', 'user.locations'])->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()->paginate(25)]);
    }

    public function editIdentity(UserExternalIdentity $identity, AuthorizationService $authorization): View
    {
        $identity->load(['user.roles', 'user.permissions', 'user.locations']);

        return view('agent.identities.edit', ['identity' => $identity, 'users' => User::query()->orderBy('name')->get(), 'roles' => Role::query()->orderBy('label')->get(), 'permissions' => Permission::query()->orderBy('group')->orderBy('label')->get(), 'locations' => Location::query()->orderBy('name')->get(), 'effective' => $identity->user ? $authorization->effectivePermissions($identity->user) : []]);
    }

    public function updateIdentity(ExternalIdentityRequest $request, UserExternalIdentity $identity, ExternalIdentityService $service): RedirectResponse
    {
        try {
            $service->update($identity, $request->validated(), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return back()->with('success', 'Identidade e acessos atualizados.');
    }

    public function observability(AgentCostService $costs): View
    {
        $month = now()->startOfMonth();
        $count = fn (string|array $type) => AgentEvent::query()->where('created_at', '>=', $month)->whereIn('event_type', (array) $type)->count();
        $metrics = [
            'today' => AgentEvent::query()->where('created_at', '>=', now()->startOfDay())->where('event_type', 'message_received')->count(),
            'month' => $count('message_received'),
            'deterministic' => $count('deterministic_command'),
            'ai' => $count('ai_called'),
            'tools' => $count('tool_executed'),
            'denied' => $count('action_denied'),
            'confirmations' => $count('confirmation_executed'),
            'duplicates' => $count('duplicate_blocked'),
            'errors' => $count(['internal_error', 'action_denied']),
            'whatsapp_messages' => AgentEvent::query()->where('created_at', '>=', $month)->where('channel', 'whatsapp')->where('event_type', 'message_received')->count(),
            'whatsapp_sent' => $count('whatsapp_response_sent'),
            'whatsapp_failures' => $count('whatsapp_send_error'),
            'whatsapp_statuses' => $count('whatsapp_status_received'),
        ];

        return view('agent.observability.index', ['metrics' => $metrics, 'costSummary' => $costs->summary(), 'costByUser' => $costs->byUser(), 'costSettings' => $costs->settings(), 'events' => AgentEvent::query()->with(['user', 'identity', 'conversation'])->latest()->paginate(30), 'pendingActions' => PendingAgentAction::query()->with('conversation.user')->latest()->limit(20)->get(), 'whatsappMessages' => WhatsAppInboundMessage::query()->latest()->limit(30)->get()]);
    }

    public function updateCosts(Request $request, AgentCostService $costs): RedirectResponse
    {
        $data = $request->validate(['monthly_budget' => 'required|decimal:0,6|min:0', 'warning_threshold' => 'required|decimal:0,6|min:0', 'saving_threshold' => 'required|decimal:0,6|min:0', 'critical_threshold' => 'required|decimal:0,6|min:0', 'monthly_host_cost' => 'required|decimal:0,6|min:0', 'automatic_saving_mode' => 'nullable|boolean']);
        $warning = BigDecimal::of($data['warning_threshold']);
        $saving = BigDecimal::of($data['saving_threshold']);
        $critical = BigDecimal::of($data['critical_threshold']);
        $budget = BigDecimal::of($data['monthly_budget']);
        if ($warning->isGreaterThan($saving) || $saving->isGreaterThan($critical) || $critical->isGreaterThan($budget)) {
            return back()->withErrors(['monthly_budget' => 'As faixas devem estar em ordem crescente e dentro do orçamento.']);
        }
        $costs->settings()->update([...$data, 'automatic_saving_mode' => $request->boolean('automatic_saving_mode')]);

        return back()->with('success', 'Política de custos atualizada.');
    }

    public function interaction(AgentConversation $conversation): View
    {
        return view('agent.observability.show', ['conversation' => $conversation->load(['user', 'messages', 'events.identity', 'pendingActions'])]);
    }
}
