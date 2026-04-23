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
        'webhook_user' => env('POSTMARK_WEBHOOK_USER', ''),
        'webhook_password' => env('POSTMARK_WEBHOOK_PASSWORD', ''),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect' => env('GOOGLE_REDIRECT_URL', env('APP_URL').'/auth/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID', ''),
        'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
        'redirect' => env('GITHUB_REDIRECT_URL', env('APP_URL').'/auth/github/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID', ''),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET', ''),
        'redirect' => env('MICROSOFT_REDIRECT_URL', env('APP_URL').'/auth/microsoft/callback'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', ''),
        'client_secret' => env('APPLE_CLIENT_SECRET', ''),
        'redirect' => env('APPLE_REDIRECT_URL', env('APP_URL').'/auth/apple/callback'),
    ],

    'paypal' => [
        'verify_webhook_signature' => env('PAYPAL_VERIFY_WEBHOOK_SIGNATURE', true),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Fastmail JMAP API token — optional prefill for integrations:connect-fastmail.
    // Generate at fastmail.com → Settings → Password & Security → API tokens.
    // Stored encrypted on the integrations row after the command runs; the
    // env var only seeds the first-time provisioning.
    'fastmail' => [
        'api_token' => env('FASTMAIL_API_TOKEN'),
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

    'lm_studio' => [
        'enabled' => env('LM_STUDIO_ENABLED', false),
        'base_url' => rtrim((string) env('LM_STUDIO_BASE_URL', 'http://localhost:1234/v1'), '/'),
        'model' => env('LM_STUDIO_MODEL', 'qwen2.5-coder-7b-instruct'),
        'timeout' => (int) env('LM_STUDIO_TIMEOUT', 120),
    ],

];
