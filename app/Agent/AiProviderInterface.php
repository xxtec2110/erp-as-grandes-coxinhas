<?php

namespace App\Agent;

interface AiProviderInterface
{
    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?AiInterpretation;
}
