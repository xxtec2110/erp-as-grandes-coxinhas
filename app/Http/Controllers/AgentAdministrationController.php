<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExternalIdentityRequest;
use App\Http\Requests\ReplaceExternalIdentityPhoneRequest;
use App\Http\Requests\StoreExternalIdentityRequest;
use App\Models\AgentConversation;
use App\Models\AgentEvent;
use App\Models\AgentUsageCost;
use App\Models\PendingAgentAction;
use App\Models\User;
use App\Models\UserExternalIdentity;
use App\Models\WhatsAppInboundMessage;
use App\Services\AgentCapabilityService;
use App\Services\AgentCostService;
use App\Services\AuthorizationService;
use App\Services\ExternalIdentityService;
use App\Services\PhoneNumberNormalizer;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentAdministrationController extends Controller
{
    public function identities(Request $request, PhoneNumberNormalizer $phones, AuthorizationService $authorization): View
    {
        $search = trim((string) $request->query('q', ''));
        $identities = UserExternalIdentity::query()->with(['user.roles', 'user.locations'])->where('channel', 'whatsapp')
            ->when($search !== '', function ($query) use ($search): void {
                $digits = preg_replace('/\D+/', '', $search) ?? '';
                $query->where(function ($query) use ($search, $digits): void {
                    $query->whereLike('display_name', '%'.$search.'%')
                        ->orWhereHas('user', fn ($user) => $user->whereLike('name', '%'.$search.'%'));
                    if ($digits !== '') {
                        $query->orWhere('phone_normalized', 'like', '%'.$digits.'%')->orWhere('external_user_id', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->latest()->paginate(25)->withQueryString();

        $whatsAppIdentities = UserExternalIdentity::query()->where('channel', 'whatsapp');

        return view('agent.identities.index', [
            'identities' => $identities, 'phones' => $phones, 'search' => $search,
            'activeCount' => (clone $whatsAppIdentities)->where('active', true)->count(),
            'inactiveCount' => (clone $whatsAppIdentities)->where('active', false)->count(),
            'blockedCount' => AgentEvent::query()->where('event_type', 'whatsapp_inbound_blocked')->count(),
            'canManage' => $authorization->allows($request->user(), 'whatsapp.identities.manage'),
        ]);
    }

    public function createIdentity(): View
    {
        return view('agent.identities.create', ['users' => User::query()->where('active', true)->with(['roles', 'locations'])->orderBy('name')->get()]);
    }

    public function storeIdentity(StoreExternalIdentityRequest $request, ExternalIdentityService $service): RedirectResponse
    {
        try {
            $identity = $service->create($request->validated(), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return redirect()->route('agent.identities.edit', $identity)->with('success', 'Acesso WhatsApp ativado.');
    }

    public function editIdentity(UserExternalIdentity $identity, AgentCapabilityService $capabilities, AuthorizationService $authorization): View
    {
        $identity->load(['user.roles', 'user.permissions', 'user.locations', 'approver']);

        return view('agent.identities.edit', [
            'identity' => $identity,
            'capabilities' => $identity->user ? $capabilities->forUser($identity->user) : [],
            'users' => User::query()->where('active', true)->with(['roles', 'locations'])->orderBy('name')->get(),
            'canManage' => $authorization->allows(request()->user(), 'whatsapp.identities.manage'),
        ]);
    }

    public function replaceIdentityPhone(ReplaceExternalIdentityPhoneRequest $request, UserExternalIdentity $identity, ExternalIdentityService $service): RedirectResponse
    {
        $data = $request->validated();
        try {
            $replacement = $service->replacePhone($identity, $data['phone'], $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return redirect()->route('agent.identities.edit', $replacement)->with('success', 'Número anterior desativado e novo acesso ativado.');
    }

    public function welcomeIdentity(UserExternalIdentity $identity, ExternalIdentityService $service, Request $request): RedirectResponse
    {
        try {
            $service->requestWelcome($identity, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['welcome' => $e->getMessage()]);
        }

        return back()->with('success', 'Boas-vindas solicitadas com segurança.');
    }

    public function updateIdentity(ExternalIdentityRequest $request, UserExternalIdentity $identity, ExternalIdentityService $service): RedirectResponse
    {
        try {
            $updated = $service->update($identity, $request->validated(), $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return redirect()->route('agent.identities.edit', $updated)->with('success', 'Identidade e acessos atualizados.');
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
            'expired' => PendingAgentAction::query()->where('created_at', '>=', $month)->where('status', 'expired')->count(),
            'duplicates' => $count('duplicate_blocked'),
            'errors' => $count(['internal_error', 'action_denied']),
            'whatsapp_messages' => AgentEvent::query()->where('created_at', '>=', $month)->where('channel', 'whatsapp')->where('event_type', 'message_received')->count(),
            'whatsapp_sent' => $count('whatsapp_response_sent'),
            'whatsapp_failures' => $count('whatsapp_send_error'),
            'whatsapp_statuses' => $count('whatsapp_status_received'),
            'whatsapp_blocked' => $count('whatsapp_inbound_blocked'),
        ];

        $provider = (string) config('ai.provider', 'disabled');
        $model = (string) config('ai.models.text', '');
        $providerStatus = $provider === 'fake' && app()->environment(['local', 'testing'])
            ? 'simulação local'
            : ($provider === 'openai' && config('ai.openai.enabled') && filled(config('ai.openai.api_key')) && $model !== '' ? 'configurado' : 'não configurado');

        $domainMetrics = AgentEvent::query()->where('created_at', '>=', $month)->whereNotNull('tool_name')->get()
            ->groupBy(fn (AgentEvent $event) => $event->metadata['domain'] ?? explode('.', $event->tool_name)[0])
            ->map(fn ($events, string $domain) => [
                'domain' => $domain,
                'reads' => $events->where('event_type', 'tool_executed')->count(),
                'pending' => $events->where('event_type', 'pending_created')->count(),
                'confirmed' => $events->where('event_type', 'confirmation_executed')->count(),
                'rejected' => $events->whereIn('event_type', ['confirmation_cancelled', 'pending_ambiguous'])->count(),
                'expired' => $events->where('event_type', 'pending_expired')->count(),
                'failed' => $events->whereIn('event_type', ['tool_failed', 'tool_validation_failed', 'internal_error'])->count(),
            ])->sortKeys();

        return view('agent.observability.index', ['metrics' => $metrics, 'domainMetrics' => $domainMetrics, 'providerConfig' => ['provider' => $provider, 'model' => $model, 'status' => $providerStatus], 'costSummary' => $costs->summary(), 'costByUser' => $costs->byUser(), 'costSettings' => $costs->settings(), 'usageCosts' => AgentUsageCost::query()->with(['user'])->latest()->limit(30)->get(), 'events' => AgentEvent::query()->with(['user', 'identity', 'conversation'])->latest()->paginate(30), 'pendingActions' => PendingAgentAction::query()->with('conversation.user')->latest()->limit(20)->get(), 'whatsappMessages' => WhatsAppInboundMessage::query()->latest()->limit(30)->get()]);
    }

    public function updateCosts(Request $request, AgentCostService $costs): RedirectResponse
    {
        $data = $request->validate(['monthly_budget' => 'required|decimal:0,6|min:0', 'warning_threshold' => 'required|decimal:0,6|min:0', 'saving_threshold' => 'required|decimal:0,6|min:0', 'critical_threshold' => 'required|decimal:0,6|min:0', 'monthly_host_cost' => 'required|decimal:0,6|min:0', 'usd_brl_rate' => 'nullable|decimal:0,8|gt:0', 'automatic_saving_mode' => 'nullable|boolean']);
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
