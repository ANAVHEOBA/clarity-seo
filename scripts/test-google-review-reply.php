#!/usr/bin/env php
<?php

/**
 * Test Google My Business Review Reply
 * Tests replying to a Google review using the correct API method
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;
use App\Models\PlatformCredential;
use App\Models\Tenant;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Test Google My Business Review Reply\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

// Get tenant and credentials
$tenant = Tenant::first();
if (!$tenant) {
    echo "❌ No tenant found. Run setup script first.\n";
    exit(1);
}

$credential = PlatformCredential::getForTenant($tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
if (!$credential) {
    echo "❌ No Google My Business credentials found.\n";
    exit(1);
}

$service = app(GoogleMyBusinessService::class);

// Force refresh the token
echo "Refreshing access token...\n";
$tokenData = $service->refreshAccessToken($credential->refresh_token);

if (!$tokenData || !isset($tokenData['access_token'])) {
    echo "❌ Failed to refresh access token.\n";
    echo "Trying existing token...\n";
    $accessToken = $service->ensureValidToken($credential);
} else {
    echo "✓ Token refreshed successfully\n";
    $credential->update([
        'access_token' => $tokenData['access_token'],
        'expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3599),
    ]);
    $accessToken = $tokenData['access_token'];
}

if (!$accessToken) {
    echo "❌ Failed to get valid access token.\n";
    exit(1);
}

echo "✓ Got valid access token\n";
echo "\n";

// Get the review name from command line or use the one from your log
$reviewName = $argv[1] ?? 'accounts/106170900387669979569/locations/16083813316172091560/reviews/AbFvOqnf8Ex-aMAaihiW__IoD8KwPcdCvhl58IV0GBQyVnhxFhaHA_UokOSV7kBt7jEBQoFGcHxc';

echo "Review Name: {$reviewName}\n";
echo "\n";

// Test reply
$replyContent = "Thank you for your feedback! We appreciate your review and look forward to serving you again. 🙏";

echo "Sending reply...\n";
echo "Reply: {$replyContent}\n";
echo "\n";

$success = $service->replyToReview($reviewName, $replyContent, $accessToken);

if ($success) {
    echo "✅ SUCCESS! Reply posted to Google My Business!\n";
    echo "\n";
    echo "🎉 Check your Google Business Profile to see the response!\n";
} else {
    echo "❌ FAILED to post reply.\n";
    echo "\n";
    echo "Check logs for details:\n";
    echo "  tail -f storage/logs/laravel.log\n";
}

echo "\n";
