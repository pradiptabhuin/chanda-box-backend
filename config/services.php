<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'otp' => [
        'driver' => env('OTP_DRIVER', 'none'),
        'ttl' => (int) env('OTP_TTL', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        // Service-account JSON key (Firebase console → Project settings → Service accounts), used for FCM.
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/private/firebase-credentials.json')),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        // Dashboard → Settings → Webhooks; used to verify X-Razorpay-Signature.
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'base_url' => env('RAZORPAY_BASE_URL', 'https://api.razorpay.com/v1'),
        // How long a payment QR stays open (Razorpay needs at least 2 minutes).
        'qr_ttl_minutes' => (int) env('RAZORPAY_QR_TTL', 15),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
