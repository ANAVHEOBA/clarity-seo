<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;

$service = app(GoogleMyBusinessService::class);
$accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');

if (!$accessToken) {
    echo "Error: No access token found in .env\n";
    exit(1);
}

echo "Testing Google My Business Integration...\n";
echo "Access Token: " . substr($accessToken, 0, 10) . "...\n\n";

// 1. Fetch Accounts
echo "1. Fetching Accounts...\n";
$accounts = $service->getAccounts($accessToken);

if ($accounts === null) {
    echo "Failed to fetch accounts. Check logs.\n";
    exit(1);
}

if (empty($accounts)) {
    echo "No accounts found.\n";
    exit(0);
}

echo "Found " . count($accounts) . " accounts:\n";
foreach ($accounts as $account) {
    echo "- Name: " . $account['name'] . "\n";
    echo "  Account Name: " . ($account['accountName'] ?? 'N/A') . "\n";
    
    // 2. Fetch Locations for this account
    echo "  Fetching Locations...\n";
    $locations = $service->getLocations($account['name'], $accessToken);
    
    if ($locations) {
        echo "  Found " . count($locations) . " locations:\n";
        foreach ($locations as $location) {
            echo "    - Name: " . $location['name'] . "\n";
            echo "      Title: " . ($location['title'] ?? 'N/A') . "\n";
        }
    } else {
        echo "  No locations found or failed to fetch.\n";
    }
    echo "\n";
}

echo "Done.\n";
