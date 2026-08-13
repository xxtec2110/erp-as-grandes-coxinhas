<?php

return [
    'monthly_budget' => env('AGENT_MONTHLY_BUDGET', '300.00'),
    'warning' => env('AGENT_COST_WARNING', '200.00'),
    'saving' => env('AGENT_COST_SAVING', '250.00'),
    'critical' => env('AGENT_COST_CRITICAL', '280.00'),
    'models' => ['text' => env('AGENT_AI_TEXT_MODEL'), 'vision' => env('AGENT_AI_VISION_MODEL'), 'document' => env('AGENT_AI_DOCUMENT_MODEL'), 'audio' => env('AGENT_AUDIO_TRANSCRIPTION_MODEL')],
];
