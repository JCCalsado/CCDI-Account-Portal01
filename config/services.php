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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'secret' => env('PAYMONGO_SECRET_KEY'),
        'public' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'return_url' => env('PAYMENT_RETURN_URL'),
        'cancel_url' => env('PAYMENT_CANCEL_URL'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    // ── PhilSMS (Primary SMS Provider) ──────────────────────────────────────
    // Dashboard: https://dashboard.philsms.com/developers
    // API Endpoint: https://dashboard.philsms.com/api/v3/
    'philsms' => [
        'token'     => env('PHILSMS_TOKEN', ''),
        'sender_id' => env('PHILSMS_SENDER_ID', 'PhilSMS'),
        'enabled'   => env('PHILSMS_ENABLED', false),
    ],

];
