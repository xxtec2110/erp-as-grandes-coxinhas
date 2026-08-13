<?php

namespace App\Agent;

use App\Models\AgentAttachment;

class UnavailableAudioTranscriptionProvider implements AudioTranscriptionProviderInterface
{
    public function transcribe(AgentAttachment $attachment): AudioTranscription
    {
        throw new AudioTranscriptionException('audio_transcription_unavailable');
    }
}
