<?php

return [
    'enabled' => (bool) env('WHATSAPP_ENABLED', false),
    'provider' => env('WHATSAPP_PROVIDER', 'meta'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'client' => env('WHATSAPP_CLIENT', 'disabled'),
    'media_downloader' => env('WHATSAPP_MEDIA_DOWNLOADER', 'disabled'),
    'max_send_attempts' => (int) env('WHATSAPP_MAX_SEND_ATTEMPTS', 3),
    'alert_email' => env('WHATSAPP_ALERT_EMAIL'),
    'graph_base_url' => env('WHATSAPP_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'media_download_enabled' => (bool) env('WHATSAPP_MEDIA_DOWNLOAD_ENABLED', false),
    'media_max_attempts' => (int) env('WHATSAPP_MEDIA_MAX_ATTEMPTS', 2),
    'media_timeout' => (int) env('WHATSAPP_MEDIA_TIMEOUT', 20),
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '55'),
    'unknown_contact_auto_reply' => (bool) env('WHATSAPP_UNKNOWN_CONTACT_AUTO_REPLY', false),
    'identity_rate_limit_per_minute' => (int) env('WHATSAPP_IDENTITY_RATE_LIMIT_PER_MINUTE', 30),
    'media_allowed_hosts' => ['lookaside.fbsbx.com', 'scontent.whatsapp.net', 'graph.facebook.com'],
];
