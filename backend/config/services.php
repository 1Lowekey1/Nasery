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

    /*
    | Social sign-in (B1). Callback URLs must be registered with each provider:
    |   {APP_URL}/api/v1/auth/social/{provider}/callback
    | A provider without credentials is reported as "not configured".
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/auth/social/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/auth/social/facebook/callback'),
    ],

    // Apple needs HTTPS and a registered domain (not testable on plain localhost).
    // Either a pre-generated client_secret JWT, or key_id + team_id + private_key.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'key_id' => env('APPLE_KEY_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'passphrase' => env('APPLE_PASSPHRASE'),
        'redirect' => env('APPLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/v1/auth/social/apple/callback'),
    ],

    // B10 payments. Driver: "fake" (built-in simulated checkout, the default
    // when no keys are set: safe for demos), "test" (PayMongo test mode, test
    // keys only) or "live" (real charges; refused outside production).
    'paymongo' => [
        'driver' => env('PAYMONGO_DRIVER', env('PAYMONGO_SECRET_KEY') ? 'test' : 'fake'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'payment_methods' => ['card', 'gcash', 'grab_pay', 'paymaya'],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
