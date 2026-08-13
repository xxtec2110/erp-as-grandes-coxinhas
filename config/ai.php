<?php

return [
    'provider' => env('AGENT_AI_PROVIDER', 'disabled'),
    'audio_provider' => env('AGENT_AUDIO_TRANSCRIPTION_PROVIDER', 'disabled'),
    'minimum_confidence' => env('AGENT_AI_MINIMUM_CONFIDENCE', '0.70'),
    'models' => ['text' => env('AGENT_AI_TEXT_MODEL'), 'vision' => env('AGENT_AI_VISION_MODEL'), 'document' => env('AGENT_AI_DOCUMENT_MODEL'), 'audio' => env('AGENT_AUDIO_TRANSCRIPTION_MODEL')],
    'openai' => ['enabled' => env('OPENAI_ENABLED', false), 'api_key' => env('OPENAI_API_KEY'), 'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), 'timeout' => env('OPENAI_TIMEOUT', 30), 'max_attempts' => env('OPENAI_MAX_ATTEMPTS', 2)],
    'live_test' => ['enabled' => env('AGENT_AI_LIVE_TEST_ENABLED', false), 'budget_brl' => env('AGENT_AI_LIVE_TEST_BUDGET_BRL', '0')],
];
