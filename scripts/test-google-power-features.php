<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;

$service = app(GoogleMyBusinessService::class);
// Using the token we just saved in the previous turn
$accessToken = env('GOOGLE_GMP_TEST_ACCESS_TOKEN');

echo "--- Testing GMB Power Features ---"
;

// 1. Get Accounts & Locations first
$accounts = $service->getAccounts($accessToken);
if (!$accounts) {
    echo "No accounts found."
;
    exit;
}

foreach ($accounts as $account) {
    echo "\nAccount: " . $account['accountName'] . "\n"
;
    $locations = $service->getLocations($account['name'], $accessToken);
    
    if (!$locations) {
        echo " - No locations found."
;
        continue;
    }

    foreach ($locations as $location) {
        $locName = $location['name']; // Format: locations/{id}
        echo " - Location: " . $location['title'] . " ($locName)"
;

        // 2. Test Insights
        echo "   Fetching Performance Insights (Last 30 days)...
";
        $start = date('Y-m-d', strtotime('-30 days'));
        $end = date('Y-m-d', strtotime('-1 day'));
        $insights = $service->getPerformanceInsights($locName, $accessToken, $start, $end);
        
        if ($insights) {
            echo "   [SUCCESS] Insights retrieved."
;
            // print_r($insights);
        } else {
            echo "   [EMPTY] No insights for this range."
;
        }

        // 3. Test Q&A
        echo "   Fetching Questions...
";
        $questions = $service->getQuestions($locName, $accessToken);
        if ($questions) {
            echo "   [SUCCESS] Found " . count($questions) . " questions."
;
        } else {
            echo "   [EMPTY] No questions found."
;
        }
    }
}

echo "\n--- Test Complete ---"
;

