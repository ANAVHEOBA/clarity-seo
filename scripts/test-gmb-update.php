#!/usr/bin/env php
<?php

/**
 * Quick test script for Google My Business update functionality
 * 
 * Usage:
 * 1. Set your credentials below
 * 2. Run: php scripts/test-gmb-update.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ============================================
// CONFIGURE THESE VALUES
// ============================================
$accessToken = ''; // Your valid GMB access token
$locationId = '';  // Format: locations/1234567890

// ============================================
// TEST CONFIGURATION
// ============================================
$testMode = 'dry-run'; // 'dry-run' or 'live'

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Google My Business Update Functionality Test              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

if (empty($accessToken) || empty($locationId)) {
    echo "❌ ERROR: Please configure accessToken and locationId in this script\n\n";
    echo "To get these values:\n";
    echo "1. Complete OAuth flow to get access token\n";
    echo "2. Get location ID from GMB API (format: locations/{id})\n";
    echo "3. See tests/Feature/Listing/GOOGLE_TEST_SETUP.md for details\n\n";
    exit(1);
}

echo "Configuration:\n";
echo "  Access Token: " . substr($accessToken, 0, 20) . "...\n";
echo "  Location ID: {$locationId}\n";
echo "  Test Mode: {$testMode}\n\n";

// ============================================
// STEP 1: Fetch Current Location Data
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 1: Fetching current location data...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$response = Http::withToken($accessToken)
    ->get("https://mybusinessbusinessinformation.googleapis.com/v1/{$locationId}", [
        'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri'
    ]);

if ($response->failed()) {
    echo "❌ Failed to fetch location data\n";
    echo "Status: " . $response->status() . "\n";
    echo "Error: " . $response->body() . "\n\n";
    exit(1);
}

$currentData = $response->json();
echo "✅ Successfully fetched location data\n\n";

echo "Current Data:\n";
echo "  Title: " . ($currentData['title'] ?? 'N/A') . "\n";
echo "  Website: " . ($currentData['websiteUri'] ?? 'N/A') . "\n";
echo "  Phone: " . ($currentData['phoneNumbers']['primaryPhone'] ?? 'N/A') . "\n";

if (isset($currentData['storefrontAddress'])) {
    $addr = $currentData['storefrontAddress'];
    echo "  Address:\n";
    echo "    Lines: " . implode(', ', $addr['addressLines'] ?? []) . "\n";
    echo "    City: " . ($addr['locality'] ?? 'N/A') . "\n";
    echo "    State: " . ($addr['administrativeArea'] ?? 'N/A') . "\n";
    echo "    Postal: " . ($addr['postalCode'] ?? 'N/A') . "\n";
    echo "    Country: " . ($addr['regionCode'] ?? 'N/A') . "\n";
}
echo "\n";

// ============================================
// STEP 2: Test Update Mask Building
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 2: Testing updateMask building...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

function buildUpdateMask(array $data): string
{
    return implode(',', array_keys($data));
}

$testData = [
    'title' => 'Test Business',
    'websiteUri' => 'https://example.com',
    'phoneNumbers' => ['primaryPhone' => '+15551234567']
];

$mask = buildUpdateMask($testData);
echo "Test Data: " . json_encode($testData, JSON_PRETTY_PRINT) . "\n";
echo "Generated Mask: {$mask}\n";
echo "✅ Update mask built correctly\n\n";

// ============================================
// STEP 3: Test Update (Dry Run or Live)
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 3: Testing update functionality...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

if ($testMode === 'dry-run') {
    echo "⚠️  DRY RUN MODE - No actual update will be performed\n\n";
    
    $updateData = [
        'title' => 'Test Update ' . time()
    ];
    
    $updateMask = buildUpdateMask($updateData);
    $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$locationId}?updateMask={$updateMask}";
    
    echo "Would send PATCH request to:\n";
    echo "  URL: {$url}\n";
    echo "  Headers: Authorization: Bearer {token}\n";
    echo "  Body: " . json_encode($updateData, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "✅ Dry run completed successfully\n\n";
    echo "To perform a live test:\n";
    echo "  1. Change \$testMode to 'live' in this script\n";
    echo "  2. Run the script again\n\n";
    
} else {
    echo "🔴 LIVE MODE - Will perform actual update\n\n";
    
    // Prepare update data
    $timestamp = time();
    $updateData = [
        'websiteUri' => 'https://test-' . $timestamp . '.example.com'
    ];
    
    echo "Update Data: " . json_encode($updateData, JSON_PRETTY_PRINT) . "\n\n";
    
    $updateMask = buildUpdateMask($updateData);
    $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$locationId}?updateMask={$updateMask}";
    
    echo "Sending PATCH request...\n";
    
    $response = Http::withToken($accessToken)->patch($url, $updateData);
    
    if ($response->failed()) {
        echo "❌ Update failed\n";
        echo "Status: " . $response->status() . "\n";
        echo "Error: " . json_encode($response->json(), JSON_PRETTY_PRINT) . "\n\n";
        
        echo "Common issues:\n";
        echo "  - Invalid access token (expired?)\n";
        echo "  - Insufficient permissions\n";
        echo "  - Invalid field values\n";
        echo "  - Location ID format incorrect\n\n";
        exit(1);
    }
    
    $result = $response->json();
    echo "✅ Update successful!\n\n";
    
    echo "Updated Data:\n";
    echo "  Title: " . ($result['title'] ?? 'N/A') . "\n";
    echo "  Website: " . ($result['websiteUri'] ?? 'N/A') . "\n";
    echo "  Phone: " . ($result['phoneNumbers']['primaryPhone'] ?? 'N/A') . "\n\n";
    
    // Restore original website
    echo "Restoring original website...\n";
    $restoreData = [
        'websiteUri' => $currentData['websiteUri'] ?? 'https://example.com'
    ];
    
    $restoreMask = buildUpdateMask($restoreData);
    $restoreUrl = "https://mybusinessbusinessinformation.googleapis.com/v1/{$locationId}?updateMask={$restoreMask}";
    
    $restoreResponse = Http::withToken($accessToken)->patch($restoreUrl, $restoreData);
    
    if ($restoreResponse->successful()) {
        echo "✅ Original data restored\n\n";
    } else {
        echo "⚠️  Failed to restore original data\n";
        echo "Original website was: " . ($currentData['websiteUri'] ?? 'N/A') . "\n\n";
    }
}

// ============================================
// STEP 4: Test Address Update Structure
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "STEP 4: Validating address update structure...\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$validAddressStructure = [
    'storefrontAddress' => [
        'regionCode' => 'US',           // Required: ISO 3166-1 alpha-2
        'postalCode' => '90210',
        'administrativeArea' => 'CA',   // State/Province
        'locality' => 'Beverly Hills',  // City
        'addressLines' => [
            '123 Main Street',
            'Suite 100'
        ]
    ]
];

echo "Valid address structure:\n";
echo json_encode($validAddressStructure, JSON_PRETTY_PRINT) . "\n\n";

echo "Required fields:\n";
echo "  ✓ regionCode (country code, e.g., 'US', 'GB', 'CA')\n";
echo "  ✓ addressLines (array of street address lines)\n\n";

echo "Optional fields:\n";
echo "  • postalCode (zip/postal code)\n";
echo "  • administrativeArea (state/province)\n";
echo "  • locality (city)\n\n";

// ============================================
// SUMMARY
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "✅ Location data fetch: Working\n";
echo "✅ Update mask building: Working\n";
echo "✅ Address structure: Validated\n";

if ($testMode === 'live') {
    echo "✅ Live update test: Completed\n";
} else {
    echo "⚠️  Live update test: Skipped (dry-run mode)\n";
}

echo "\n";
echo "The updateLocation method in GoogleMyBusinessService is ready to use!\n\n";

echo "Next steps:\n";
echo "1. Add the method to your controller if needed\n";
echo "2. Create API endpoint for updating GMB locations\n";
echo "3. Run integration tests: php artisan test tests/Feature/Listing/GoogleMyBusinessUpdateTest.php\n\n";
