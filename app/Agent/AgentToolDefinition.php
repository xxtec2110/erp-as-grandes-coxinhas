<?php

namespace App\Agent;

class AgentToolDefinition
{
    public function __construct(public string $name, public string $permission, public bool $locationScoped, public bool $confirmationRequired, public bool $writesData, public array $inputSchema, public array $outputSchema, public string $serviceClass) {}
}
