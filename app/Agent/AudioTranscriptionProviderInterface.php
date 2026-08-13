<?php

namespace App\Agent;

use App\Models\AgentAttachment;

interface AudioTranscriptionProviderInterface
{
    public function transcribe(AgentAttachment $attachment): AudioTranscription;
}
