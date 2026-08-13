<?php

return [
    'disk' => env('ATTACHMENT_DISK', 'local'),
    'max_image_mb' => (int) env('AGENT_MAX_IMAGE_MB', 10),
    'max_document_mb' => (int) env('AGENT_MAX_DOCUMENT_MB', 15),
    'max_audio_mb' => (int) env('AGENT_MAX_AUDIO_MB', 16),
    'temporary_retention_days' => (int) env('AGENT_TEMPORARY_RETENTION_DAYS', 7),
    'allowed_mimes' => [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'audio/ogg' => ['ogg', 'opus'],
        'audio/mpeg' => ['mp3'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/amr' => ['amr'],
    ],
];
