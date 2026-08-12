<?php

namespace App\Http\Controllers;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentService;
use App\Models\UserExternalIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AgentSimulatorController extends Controller
{
    public function index(): View
    {
        return view('agent.simulator');
    }

    public function send(Request $request, ErpAgentService $agent): View
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $externalId = 'local-user-'.$request->user()->id;
        UserExternalIdentity::query()->firstOrCreate(['channel' => 'local', 'external_user_id' => $externalId], ['user_id' => $request->user()->id, 'active' => true]);
        $response = $agent->handle(new AgentMessage('local', $externalId, (string) Str::uuid(), $data['text'], metadata: $request->input('fake_intent', [])));

        return view('agent.simulator', ['sentText' => $data['text'], 'agentResponse' => $response]);
    }
}
