#!/usr/bin/env php
<?php

/**
 * Test GMB Review Reply Flow
 * This tests the complete flow of replying to a Google review
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;
use App\Services\Review\ReviewService;
use App\Models\PlatformCredential;
use App\Models\Tenant;
use App\Models\Review;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Test GMB Review Reply Flow\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

// Get tenant
$tenant = Tenant::first();
if (!$tenant) {
    echo "❌ No tenant found.\n";
    exit(1);
}

echo "✓ Tenant: {$tenant->name}\n\n";

// Get credentials
$credential = PlatformCredential::getForTenant($tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
if (!$credential) {
    echo "❌ No Google My Business credentials found.\n";
    echo "\nTo test GMB reply functionality, you need:\n";
    echo "1. Valid GMB credentials with refresh token\n";
    echo "2. A location with reviews\n";
    echo "3. Reviews synced from GMB API (not Places API)\n";
    exit(1);
}

echo "✓ Found GMB credentials\n";

// Check token
$service = app(GoogleMyBusinessService::class);
$accessToken = $service->ensureValidToken($credential);

if (!$accessToken) {
    echo "❌ Failed to get valid access token.\n";
    echo "Token may be expired and refresh failed.\n";
    exit(1);
}

echo "✓ Access token is valid\n\n";

// Find a Google review
$review = Review::where('platform', 'google')
    ->whereNotNull('external_id')
    ->whereDoesntHave('response')
    ->first();

if (!$review) {
    echo "⚠️  No Google reviews without responses found.\n";
    echo "\nTrying to find any Google review...\n";
    
    $review = Review::where('platform', 'google')
        ->whereNotNull('external_id')
        ->first();
    
    if (!$review) {
        echo "❌ No Google reviews found at all.\n";
        echo "\nYou need to:\n";
        echo "1. Sync reviews from GMB API\n";
        echo "2. Ensure reviews have proper external_id format\n";
        exit(1);
    }
    
    echo "✓ Found review (already has response)\n";
}

echo "\n--- Review Details ---\n";
echo "ID: {$review->id}\n";
echo "Author: {$review->author_name}\n";
echo "Rating: {$review->rating} stars\n";
echo "Content: " . substr($review->content ?? 'No content', 0, 100) . "...\n";
echo "External ID: {$review->external_id}\n";
echo "Platform: {$review->platform}\n";

// Check external ID format
if (!str_contains($review->external_id, 'accounts/')) {
    echo "\n⚠️  WARNING: External ID doesn't contain 'accounts/'\n";
    echo "Expected format: accounts/{accountId}/locations/{locationId}/reviews/{reviewId}\n";
    echo "Current format: {$review->external_id}\n";
    echo "\nThis review was likely synced from Google Places API, not GMB API.\n";
    echo "GMB API reviews are required for replying.\n";
    echo "\nTo fix:\n";
    echo "1. Ensure you have GMB credentials set up\n";
    echo "2. Run: php artisan reviews:sync\n";
    echo "3. Or use the sync endpoint in your API\n";
    exit(1);
}

echo "\n✓ External ID format is correct for GMB API\n";

// Test reply
echo "\n--- Testing Reply ---\n";
$testReply = "Thank you for your review! We appreciate your feedback.";
echo "Reply content: {$testReply}\n\n";

echo "Attempting to send reply to GMB...\n";
$success = $service->replyToReview($review->external_id, $testReply, $accessToken);

if ($success) {
    echo "✅ SUCCESS! Reply sent to Google My Business\n";
    echo "\nThe reply should now be visible on:\n";
    echo "- Google Search\n";
    echo "- Google Maps\n";
    echo "- Google Business Profile dashboard\n";
} else {
    echo "❌ FAILED to send reply\n";
    echo "\nCheck the logs for details:\n";
    echo "  tail -f storage/logs/laravel.log | grep -A 5 'Google review'\n";
    echo "\nCommon issues:\n";
    echo "1. Location not verified in GMB\n";
    echo "2. Insufficient permissions (need business.manage scope)\n";
    echo "3. Review ID format incorrect\n";
    echo "4. Token expired or invalid\n";
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Test Complete\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";
