<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database security context
    |--------------------------------------------------------------------------
    |
    | Every /api/v1 request runs inside a transaction that switches into this
    | NOLOGIN role (created by database/supabase/0027_app_role_grants.sql) and
    | publishes the caller's users.id as auth.uid(), so PostgreSQL RLS acts as
    | the backstop behind Laravel's own authorization.
    |
    */

    'db' => [
        'app_role' => env('DB_APP_ROLE', 'nasery_app'),
        'rls_enabled' => (bool) env('DB_RLS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Empty (default): trust no X-Forwarded-* headers; request IP = TCP peer.
    | "*" (or "**"): trust only the immediate peer (one proxy in front).
    | CIDR list, e.g. "10.0.0.0/8,0.0.0.0/0,::/0": trust a chain of proxies
    | (e.g. Vercel -> Railway edge). Trusting 0.0.0.0/0 is only safe when the
    | app is NOT reachable except through those proxies; otherwise clients can
    | spoof their IP and dodge per-IP rate limits.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    |--------------------------------------------------------------------------
    | Rate limits (starting values from the backend conventions)
    |--------------------------------------------------------------------------
    |
    | [max attempts, decay in minutes, key: ip|user|user_or_ip]
    |
    */

    'rate_limits' => [
        'login' => [5, 1, 'ip'],
        // Per target account, so a botnet rotating IPs still can't brute-force one user.
        'login-account' => [10, 15, 'email'],
        'register' => [5, 60, 'ip'],
        'password-reset' => [5, 60, 'ip'],
        'email-verification' => [5, 60, 'user'],
        'public' => [60, 1, 'ip'],
        'api' => [120, 1, 'user_or_ip'],
        'sensitive' => [30, 1, 'user_or_ip'],
        'checkout' => [10, 1, 'user_or_ip'],
        // Guest ticket checkout: e-mail codes per attendee (up to 10 per registration, plus resends).
        'guest-codes' => [20, 60, 'ip'],
        'checkin' => [120, 1, 'user'],
        'webhook' => [300, 1, 'ip'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (B1)
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Where the SPA lives; social-login callbacks redirect back here.
        'frontend_url' => rtrim((string) env('FRONTEND_APP_URL', 'http://localhost:3000'), '/'),
        'codes' => [
            'length' => 6,
            'ttl_minutes' => 10,
            'max_attempts' => 5,
            'resend_cooldown_seconds' => 60,
        ],
        'social' => [
            // Browser-bound flow: state + one-time exchange code lifetimes.
            'state_ttl_minutes' => 10,
            'exchange_ttl_seconds' => 120,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'header' => 'Idempotency-Key',
        'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
        // A request still "processing" after this long is considered crashed
        // and its key may be reclaimed.
        'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database backups (safety net before tests / risky operations)
    |--------------------------------------------------------------------------
    */

    'backup' => [
        'pg_dump_path' => env('PG_DUMP_PATH', 'pg_dump'),
        'directory' => storage_path('backups'),
        'keep' => (int) env('BACKUP_KEEP', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing mail (B13)
    |--------------------------------------------------------------------------
    */

    'mail' => [
        // false = no e-mail at all for this process (set at runtime by nasery:demo:seed).
        'enabled' => true,
        // Rehearsal safety net: when set (comma-separated addresses and/or
        // @domains), every other recipient is skipped and logged "suppressed".
        'only_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_ONLY_TO', ''))))),
        // Reserved/test domains never receive mail (seed and E2E data use them).
        'never_to' => ['.test', '.example', '.invalid', '.localhost', '@example.com', '@example.net', '@example.org'],
        // Day-before reminders: sessions starting within this many hours.
        'reminder_hours' => (int) env('NASERY_REMINDER_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Check-in (B11)
    |--------------------------------------------------------------------------
    */

    'checkin' => [
        // A session accepts tickets from this long before it starts until it ends (user).
        'opens_before_minutes' => (int) env('NASERY_CHECKIN_OPENS_BEFORE_MINUTES', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo dataset (nasery:demo:seed)
    |--------------------------------------------------------------------------
    |
    | Accounts use the reserved demo domain (never e-mailed; the test purge
    | leaves them alone). DEMO_PASSWORD is set by the team in .env.
    |
    */

    'demo' => [
        'domain' => 'demo.nasery.test',
        'password' => env('DEMO_PASSWORD'),
    ],
];
