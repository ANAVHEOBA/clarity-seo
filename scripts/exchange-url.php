<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\PlatformCredential;
use App\Models\Tenant;

$url = $argv[1] ?? null;

if (!$url) {
    echo "Usage: php scripts/exchange-url.php \"<full_redirect_url>\"\n";
    exit(1);
}

// Extract code from URL
$parsedUrl = parse_url($url);
parse_str($parsedUrl['query'] ?? '', $queryParams);
$code = $queryParams['code'] ?? null;

if (!$code) {
    echo "Error: No 'code' found in the URL.\n";
    exit(1);
}

echo "Code extracted: " . substr($code, 0, 10) . "...\n";

// Get service and config
$clientId = config('google.my_business.client_id');
$clientSecret = config('google.my_business.client_secret');
$redirectUri = config('google.my_business.redirect_uri');

echo "Exchanging code for token (Redirect URI: $redirectUri)...";

$response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);

if (!$response->successful()) {
    echo "Error exchanging token:\n";
    print_r($response->json());
    exit(1);
}

$tokenData = $response->json();
echo "Success! Access Token received.\n";

// Store in DB
$tenant = Tenant::first();
if (!$tenant) {
    $tenant = Tenant::factory()->create(['id' => 1]);
}

PlatformCredential::updateOrCreate(
    [
        'tenant_id' => $tenant->id,
        'platform' => 'google_my_business',
        'external_id' => 'pending_setup',
    ],
    [
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'] ?? null,
        'token_type' => 'Bearer',
        'expires_at' => now()->addSeconds($tokenData['expires_in']),
        'scopes' => config('google.my_business.scopes'),
        'is_active' => true,
    ]
);

echo "Credentials saved to database for Tenant ID: {$tenant->id}\n";