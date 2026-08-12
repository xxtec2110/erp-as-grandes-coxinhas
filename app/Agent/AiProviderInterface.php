<?php

namespace App\Agent;

interface AiProviderInterface
{
    /** @return array{tool:string,arguments:array<string,mixed>}|null */
    public function interpret(AgentMessage $message, array $availableTools, array $context = []): ?array;
}
