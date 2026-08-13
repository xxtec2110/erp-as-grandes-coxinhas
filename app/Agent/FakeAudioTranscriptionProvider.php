<?php

namespace App\Agent;

use App\Models\AgentAttachment;

class FakeAudioTranscriptionProvider implements AudioTranscriptionProviderInterface
{
    public function transcribe(AgentAttachment $attachment): AudioTranscription
    {
        return new AudioTranscription((string) ($attachment->metadata['fake_transcription'] ?? ''), usage: ['model' => 'fake-audio']);
    }
}
