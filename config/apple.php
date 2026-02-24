<?php

return [
    'app_store' => [
        'api_base_url' => env('APPLE_APP_STORE_API_BASE_URL', 'https://api.appstoreconnect.apple.com'),
        'jwt_ttl_minutes' => (int) env('APPLE_APP_STORE_JWT_TTL_MINUTES', 20),
        'jwt_audience' => env('APPLE_APP_STORE_JWT_AUDIENCE', 'appstoreconnect-v1'),
    ],
];
