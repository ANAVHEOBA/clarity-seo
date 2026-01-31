#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Listing\GoogleMyBusinessService;
use App\Models\PlatformCredential;
use App\Models\Tenant;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Fetch Google My Business Reviews\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

$tenant = Tenant::first();
$credential = PlatformCredential::getForTenant($tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);

if (!$credential) {
    echo "❌ No Google My Business credentials found.\n";
    exit(1);
}

$service = app(GoogleMyBusinessService::class);

// Refresh token
echo "Refreshing access token...\n";
$tokenData = $service->refreshAccessToken($credential->refresh_token);

if (!$tokenData || !isset($tokenData['access_token'])) {
    echo "❌ Failed to refresh access token.\n";
    exit(1);
}

$credential->update([
    'access_token' => $tokenData['access_token'],
    'expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3599),
]);

$accessToken = $tokenData['access_token'];
echo "✓ Token refreshed\n\n";

// Get location name from credential
$locationName = $credential->metadata['location_name'] ?? $credential->external_id;

if (!$locationName) {
    echo "❌ No location name found in credentials.\n";
    exit(1);
}

echo "Location: {$locationName}\n\n";

// Fetch reviews
echo "Fetching reviews...\n";
$reviewsData = $service->getReviews($locationName, $accessToken);

if (!$reviewsData) {
    echo "❌ Failed to fetch reviews or no reviews found.\n";
    exit(1);
}

if (!isset($reviewsData['reviews']) || empty($reviewsData['reviews'])) {
    echo "ℹ️  No reviews found for this location.\n";
    exit(0);
}

echo "✓ Found " . count($reviewsData['reviews']) . " review(s)\n\n";

foreach ($reviewsData['reviews'] as $index => $review) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Review #" . ($index + 1) . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Name: " . ($review['name'] ?? 'N/A') . "\n";
    echo "Reviewer: " . ($review['reviewer']['displayName'] ?? 'Anonymous') . "\n";
    echo "Rating: " . ($review['starRating'] ?? 'N/A') . "\n";
    echo "Comment: " . ($review['comment'] ?? 'No comment') . "\n";
    echo "Created: " . ($review['createTime'] ?? 'N/A') . "\n";
    
    if (isset($review['reviewReply'])) {
        echo "Has Reply: YES\n";
        echo "Reply: " . ($review['reviewReply']['comment'] ?? 'N/A') . "\n";
    } else {
        echo "Has Reply: NO\n";
    }
    
    echo "\n";
}

echo "\n";
echo "To reply to a review, use:\n";
echo "  php scripts/test-google-review-reply.php '<review-name>'\n";
echo "\n";
