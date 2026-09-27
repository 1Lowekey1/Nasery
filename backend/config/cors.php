<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Only known Nuxt origins may call the API with credentials. Wildcard
    | origins are never combined with credentialed requests. In production
    | the frontend proxies /api through its own origin, so this list mostly
    | matters for local development and preview deployments.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URLS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Request-Id',
        'Idempotency-Key',
    ],

    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'Idempotency-Replayed'],

    'max_age' => 600,

    'supports_credentials' => true,

];
