<?php

namespace App\Agent;

class UnavailableAiProvider implements AiProviderInterface
{
    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?array
    {
        throw new AiProviderUnavailableException('ai_provider_unavailable');
    }
}
