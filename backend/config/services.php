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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'), // Will be overridden dynamically per flow
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'), // Empty — auto-generated from private key
        'key_id' => env('APPLE_KEY_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'), // Absolute path to .p8 file
        'redirect' => env('APPLE_REDIRECT_URI'), // Will be overridden dynamically per flow
    ],

    'tiktok' => [
        'client_id' => env('TIKTOK_CLIENT_ID'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI') // Will be overridden dynamically per flow
    ],

    'comfyui' => [
        'default_provider' => env('COMFYUI_DEFAULT_PROVIDER', 'self_hosted'),
        'lease_ttl_seconds' => env('COMFYUI_LEASE_TTL_SECONDS', 900),
        'max_attempts' => env('COMFYUI_MAX_ATTEMPTS', 3),
        'presigned_ttl_seconds' => env('COMFYUI_PRESIGNED_TTL_SECONDS', 900),
        'upload_max_bytes' => env('COMFYUI_UPLOAD_MAX_BYTES', 1073741824),
        'workflow_disk' => env('COMFYUI_WORKFLOW_DISK', 's3'),
      'models_disk' => env('COMFYUI_MODELS_DISK', 'comfyui_models'),
      'logs_disk' => env('COMFYUI_LOGS_DISK', 'comfyui_logs'),
      'asset_ops_secret' => env('COMFYUI_ASSET_OPS_SECRET'),
      'asset_upload_prefix' => env('COMFYUI_ASSET_UPLOAD_PREFIX', 'assets'),
      'asset_bundle_prefix' => env('COMFYUI_ASSET_BUNDLE_PREFIX', 'bundles'),
        'allowed_mime_types' => [
            'video/mp4',
            'video/quicktime',
            'video/webm',
            'video/x-matroska',
        ],
        'fleet_secret' => env('COMFYUI_FLEET_SECRET'),
        'max_fleet_workers' => env('COMFYUI_MAX_FLEET_WORKERS', 50),
        'stale_worker_hours' => env('COMFYUI_STALE_WORKER_HOURS', 2),
        'validate_asg_instance' => env('COMFYUI_VALIDATE_ASG_INSTANCE', false),
        'aws_region' => env('COMFYUI_AWS_REGION', 'us-east-1'),
        'emit_workflow_metrics' => env('COMFYUI_EMIT_WORKFLOW_METRICS', true),
        'emit_fleet_metrics' => env('COMFYUI_EMIT_FLEET_METRICS', true),
    ],

];
