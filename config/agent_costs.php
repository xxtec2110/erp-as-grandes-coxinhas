<?php

return [
    'provider' => env('AGENT_AI_PROVIDER', 'disabled'),
    'monthly_budget' => env('AGENT_MONTHLY_BUDGET', '300.00'),
    'warning' => env('AGENT_COST_WARNING', '200.00'),
    'saving' => env('AGENT_COST_SAVING', '250.00'),
    'critical' => env('AGENT_COST_CRITICAL', '280.00'),
    'models' => ['text' => env('AGENT_TEXT_MODEL'), 'vision' => env('AGENT_VISION_MODEL'), 'audio' => env('AGENT_AUDIO_TRANSCRIPTION_MODEL')],
];
