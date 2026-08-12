<?php

return [
    'disk' => env('ATTACHMENT_DISK', 'local'),
    'max_image_mb' => (int) env('AGENT_MAX_IMAGE_MB', 10),
    'max_document_mb' => (int) env('AGENT_MAX_DOCUMENT_MB', 15),
    'temporary_retention_days' => (int) env('AGENT_TEMPORARY_RETENTION_DAYS', 7),
    'allowed_mimes' => [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ],
];
