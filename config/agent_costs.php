<?php

return [
    'monthly_budget' => env('AGENT_MONTHLY_BUDGET', '300.00'),
    'warning' => env('AGENT_COST_WARNING', '200.00'),
    'saving' => env('AGENT_COST_SAVING', '250.00'),
    'critical' => env('AGENT_COST_CRITICAL', '280.00'),
    'usd_brl_rate' => env('AGENT_COST_USD_BRL_RATE'),
    'model_rates' => [
        'gpt-4.1-mini' => [
            'input_per_million_usd' => '0.40',
            'cached_input_per_million_usd' => '0.10',
            'output_per_million_usd' => '1.60',
            'version' => '2026-08-15',
            'effective_date' => '2026-08-15',
        ],
    ],
    'models' => ['text' => env('AGENT_AI_TEXT_MODEL'), 'vision' => env('AGENT_AI_VISION_MODEL'), 'document' => env('AGENT_AI_DOCUMENT_MODEL'), 'audio' => env('AGENT_AUDIO_TRANSCRIPTION_MODEL')],
];
