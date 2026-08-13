<?php

namespace App\Http\Controllers;

use App\Agent\AgentMessage;
use App\Agent\ErpAgentService;
use App\Models\UserExternalIdentity;
use App\Services\AgentAttachmentService;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AgentSimulatorController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        return view('agent.simulator', ['locations' => $authorization->accessibleLocations($request->user())]);
    }

    public function send(Request $request, ErpAgentService $agent, AgentAttachmentService $attachments, AuthorizationService $authorization): View
    {
        $data = $request->validate(['text' => ['nullable', 'required_without:attachment', 'string', 'max:4000'], 'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png'], 'location_id' => ['nullable', 'required_with:attachment', 'integer', 'exists:locations,id']]);
        $externalId = 'local-user-'.$request->user()->id;
        UserExternalIdentity::query()->updateOrCreate(['channel' => 'local', 'external_user_id' => $externalId], ['user_id' => $request->user()->id, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'free_chat_allowed' => true, 'image_allowed' => true, 'document_allowed' => true]);
        $linked = [];
        $type = 'text';
        if ($request->hasFile('attachment')) {
            $stored = $attachments->store($request->file('attachment'), 'agent', (int) $data['location_id'], 'temporary', $request->user());
            $linked[] = $stored->id;
            $type = str_starts_with($stored->mime_type, 'image/') ? 'image' : 'document';
        }
        $response = $agent->handle(new AgentMessage('local', $externalId, (string) Str::uuid(), $data['text'] ?? null, $type, $linked));

        return view('agent.simulator', ['sentText' => $data['text'] ?: ($request->file('attachment')?->getClientOriginalName() ?? ''), 'agentResponse' => $response, 'locations' => $authorization->accessibleLocations($request->user())]);
    }
}
