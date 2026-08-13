<?php

namespace App\Http\Controllers;

use App\Agent\AgentMessage;
use App\Agent\AiProviderInterface;
use App\Agent\AudioTranscriptionProviderInterface;
use App\Agent\ErpAgentService;
use App\Models\AgentUsageCost;
use App\Models\UserExternalIdentity;
use App\Services\AgentAttachmentService;
use App\Services\AgentMediaService;
use App\Services\AuthorizationService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentSimulatorController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        return view('agent.simulator', ['locations' => $authorization->accessibleLocations($request->user()), 'liveAvailable' => $this->liveAvailable($request)]);
    }

    public function send(Request $request, AgentAttachmentService $attachments, AuthorizationService $authorization): View
    {
        $data = $request->validate(['provider' => ['required', 'in:fake,live'], 'text' => ['nullable', 'required_without:attachment', 'string', 'max:4000'], 'fake_transcription' => ['nullable', 'string', 'max:4000'], 'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,ogg,opus,mp3,m4a,mp4,amr', 'extensions:pdf,jpg,jpeg,png,ogg,opus,mp3,m4a,mp4,amr'], 'location_id' => ['nullable', 'required_with:attachment', 'integer', 'exists:locations,id']]);
        if ($data['provider'] === 'live' && ! $this->liveAvailable($request)) {
            throw ValidationException::withMessages(['provider' => 'O Live Test exige ambiente local, administrador, flag ativa, chave/modelo configurados e orçamento disponível.']);
        }
        config()->set('ai.provider', $data['provider'] === 'live' ? 'openai' : 'fake');
        config()->set('ai.audio_provider', $data['provider'] === 'live' ? 'openai' : 'fake');
        app()->forgetInstance(AiProviderInterface::class);
        app()->forgetInstance(AudioTranscriptionProviderInterface::class);
        app()->forgetInstance(AgentMediaService::class);
        $agent = app(ErpAgentService::class);
        $externalId = 'local-user-'.$request->user()->id;
        UserExternalIdentity::query()->updateOrCreate(['channel' => 'local', 'external_user_id' => $externalId], ['user_id' => $request->user()->id, 'status' => 'approved', 'active' => true, 'structured_commands_allowed' => true, 'free_chat_allowed' => true, 'image_allowed' => true, 'document_allowed' => true, 'voice_allowed' => true]);
        $linked = [];
        $type = 'text';
        if ($request->hasFile('attachment')) {
            $stored = $attachments->store($request->file('attachment'), 'agent', (int) $data['location_id'], 'temporary', $request->user());
            $linked[] = $stored->id;
            $type = str_starts_with($stored->mime_type, 'image/') ? 'image' : (str_starts_with($stored->mime_type, 'audio/') ? 'audio' : 'document');
            if ($type === 'audio') {
                if ($data['provider'] === 'fake' && blank($data['fake_transcription'] ?? null)) {
                    throw ValidationException::withMessages(['fake_transcription' => 'Informe a transcrição esperada para testar um áudio no modo Fake.']);
                }
                if ($data['provider'] === 'fake') {
                    $metadata = $stored->metadata ?? [];
                    $metadata['fake_transcription'] = $data['fake_transcription'];
                    $stored->update(['metadata' => $metadata]);
                }
                $audioMessage = new AgentMessage('local', $externalId, (string) Str::uuid(), messageType: 'audio', attachments: $linked);
                $transcription = app(AgentMediaService::class)->transcribeStored($stored->refresh(), $request->user(), (int) $data['location_id'], $audioMessage);
                if (blank($transcription)) {
                    throw ValidationException::withMessages(['attachment' => 'Não foi possível transcrever o áudio com segurança.']);
                }
                $data['text'] = $transcription;
                $type = 'transcribed_audio';
            }
        }
        $response = $agent->handle(new AgentMessage('local', $externalId, (string) Str::uuid(), $data['text'] ?? null, $type, $linked));

        return view('agent.simulator', ['sentText' => $data['text'] ?: ($request->file('attachment')?->getClientOriginalName() ?? ''), 'agentResponse' => $response, 'locations' => $authorization->accessibleLocations($request->user()), 'selectedProvider' => $data['provider'], 'liveAvailable' => $this->liveAvailable($request)]);
    }

    private function liveAvailable(Request $request): bool
    {
        $spent = (string) AgentUsageCost::query()->where('provider', 'openai')->where('created_at', '>=', now()->startOfMonth())->sum('estimated_cost');
        $budget = BigDecimal::of((string) config('ai.live_test.budget_brl', '0'));

        return app()->environment('local') && (bool) $request->user()?->is_super_admin
            && (bool) config('ai.live_test.enabled') && (bool) config('ai.openai.enabled')
            && filled(config('ai.openai.api_key')) && filled(config('ai.models.text'))
            && $budget->isPositive() && BigDecimal::of($spent)->isLessThan($budget);
    }
}
