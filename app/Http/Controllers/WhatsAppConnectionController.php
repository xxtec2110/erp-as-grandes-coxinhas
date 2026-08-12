<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppInboundMessage;
use App\Services\WhatsAppConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WhatsAppConnectionController extends Controller
{
    public function index(WhatsAppConnectionService $service): View
    {
        return view('agent.whatsapp.index', [
            'connection' => $service->current(),
            'messages' => WhatsAppInboundMessage::query()->latest('received_at')->paginate(30),
            'pendingCount' => WhatsAppInboundMessage::query()->whereIn('status', ['received', 'queued', 'processing', 'failed', 'review_required'])->count(),
        ]);
    }

    public function check(WhatsAppConnectionService $service): RedirectResponse
    {
        $service->check();

        return back()->with('success', 'Estado da conexão atualizado.');
    }
}
