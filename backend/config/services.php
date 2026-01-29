<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'comfyui' => [
        'worker_token' => env('COMFYUI_WORKER_TOKEN'),
        'default_provider' => env('COMFYUI_DEFAULT_PROVIDER', 'local'),
        'lease_ttl_seconds' => env('COMFYUI_LEASE_TTL_SECONDS', 900),
        'max_attempts' => env('COMFYUI_MAX_ATTEMPTS', 3),
        'presigned_ttl_seconds' => env('COMFYUI_PRESIGNED_TTL_SECONDS', 900),
        'upload_max_bytes' => env('COMFYUI_UPLOAD_MAX_BYTES', 1073741824),
        'allowed_mime_types' => [
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/x-matroska',
        ],
    ],

];
