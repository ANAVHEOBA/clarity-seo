<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;

$service = app(GoogleMyBusinessService::class);
$accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');

echo "Fetching Accounts from Google...\n";
$accounts = $service->getAccounts($accessToken);

if (!$accounts) {
    echo "No accounts found or API error.\n";
    exit(1);
}

foreach ($accounts as $account) {
    echo "Account: " . $account['accountName'] . " (" . $account['name'] . ")\n";
    echo "Fetching Locations...\n";
    $locations = $service->getLocations($account['name'], $accessToken);
    
    if ($locations) {
        foreach ($locations as $location) {
            echo " - Location: " . $location['title'] . " (" . $location['name'] . ")\n";
        }
    } else {
        echo " - No locations found.\n";
    }
}
