<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWhatsAppBusinessPhoneRequest;
use App\Models\WhatsAppInboundMessage;
use App\Services\AgentChannelHealthService;
use App\Services\PhoneNumberNormalizer;
use App\Services\WhatsAppConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WhatsAppConnectionController extends Controller
{
    public function index(WhatsAppConnectionService $service, AgentChannelHealthService $health, PhoneNumberNormalizer $phones): View
    {
        $connection = $service->current();

        return view('agent.whatsapp.index', [
            'connection' => $connection,
            'health' => $health->summary($connection),
            'phones' => $phones,
            'messages' => WhatsAppInboundMessage::query()->latest('received_at')->paginate(30),
            'pendingCount' => WhatsAppInboundMessage::query()->whereIn('status', ['received', 'queued', 'processing', 'failed', 'review_required'])->count(),
        ]);
    }

    public function check(WhatsAppConnectionService $service): RedirectResponse
    {
        $service->check();

        return back()->with('success', 'Estado da conexão atualizado.');
    }

    public function updateBusinessPhone(UpdateWhatsAppBusinessPhoneRequest $request, WhatsAppConnectionService $service): RedirectResponse
    {
        $service->configureBusinessPhone($request->validated('business_phone'), $request->user());

        return back()->with('success', 'Número empresarial atualizado sem alterar identidades de usuários.');
    }
}
