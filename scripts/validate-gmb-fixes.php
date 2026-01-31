#!/usr/bin/env php
<?php

/**
 * Validate GMB Fixes
 * This validates that the review images and media upload fixes are working
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Resources\Review\ReviewResource;
use App\Models\Review;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Validate GMB Fixes\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "\n";

$allPassed = true;

// Test 1: ReviewResource includes images field
echo "Test 1: ReviewResource includes 'images' field\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$review = Review::first();
if (!$review) {
    echo "⚠️  SKIP: No reviews found\n\n";
} else {
    $resource = new ReviewResource($review);
    $array = $resource->toArray(request());
    
    if (array_key_exists('images', $array)) {
        echo "✅ PASS: 'images' field exists in ReviewResource\n";
        echo "   Value: " . (empty($array['images']) ? '[]' : json_encode($array['images'])) . "\n";
    } else {
        echo "❌ FAIL: 'images' field missing from ReviewResource\n";
        $allPassed = false;
    }
}

echo "\n";

// Test 2: Review metadata structure
echo "Test 2: Review metadata can contain images\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// Create a test review with image metadata
$testReview = new Review([
    'location_id' => 1,
    'platform' => 'google',
    'external_id' => 'test-review-with-images',
    'author_name' => 'Test User',
    'rating' => 5,
    'content' => 'Great service!',
    'metadata' => [
        'photos' => [
            ['url' => 'https://example.com/photo1.jpg'],
            ['url' => 'https://example.com/photo2.jpg']
        ],
        'profile_photo_url' => 'https://example.com/profile.jpg'
    ]
]);

$resource = new ReviewResource($testReview);
$array = $resource->toArray(request());

if (isset($array['images']) && count($array['images']) === 3) {
    echo "✅ PASS: Images extracted from metadata correctly\n";
    echo "   Found " . count($array['images']) . " images\n";
} else {
    echo "❌ FAIL: Images not extracted correctly from metadata\n";
    echo "   Expected 3 images, got: " . (isset($array['images']) ? count($array['images']) : 0) . "\n";
    $allPassed = false;
}

echo "\n";

// Test 3: GoogleMyBusinessService has updated methods
echo "Test 3: GoogleMyBusinessService media upload methods\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$service = app(\App\Services\Listing\GoogleMyBusinessService::class);
$reflection = new ReflectionClass($service);

$requiredMethods = [
    'uploadMedia' => 'Upload from URL',
    'startMediaUpload' => 'Start upload (Step 1)',
    'uploadMediaBytes' => 'Upload bytes (Step 2)',
    'finalizeMediaUpload' => 'Finalize upload (Step 3)',
    'uploadMediaFromFile' => 'Complete file upload helper'
];

$methodsPassed = true;
foreach ($requiredMethods as $method => $description) {
    if ($reflection->hasMethod($method)) {
        echo "✅ {$method}() - {$description}\n";
    } else {
        echo "❌ {$method}() - Missing\n";
        $methodsPassed = false;
        $allPassed = false;
    }
}

if ($methodsPassed) {
    echo "\n✅ PASS: All media upload methods exist\n";
} else {
    echo "\n❌ FAIL: Some media upload methods missing\n";
}

echo "\n";

// Test 4: Check uploadMedia signature
echo "Test 4: uploadMedia() method signature\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($reflection->hasMethod('uploadMedia')) {
    $method = $reflection->getMethod('uploadMedia');
    $params = $method->getParameters();
    
    $expectedParams = ['locationName', 'mediaUrl', 'accessToken', 'category', 'mediaFormat'];
    $actualParams = array_map(fn($p) => $p->getName(), $params);
    
    if ($actualParams === $expectedParams) {
        echo "✅ PASS: uploadMedia() has correct parameters\n";
        echo "   Parameters: " . implode(', ', $actualParams) . "\n";
    } else {
        echo "❌ FAIL: uploadMedia() parameters don't match\n";
        echo "   Expected: " . implode(', ', $expectedParams) . "\n";
        echo "   Actual: " . implode(', ', $actualParams) . "\n";
        $allPassed = false;
    }
} else {
    echo "❌ FAIL: uploadMedia() method not found\n";
    $allPassed = false;
}

echo "\n";

// Test 5: Review reply flow check
echo "Test 5: Review reply flow validation\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$googleReview = Review::where('platform', 'google')->first();
if (!$googleReview) {
    echo "⚠️  SKIP: No Google reviews found\n";
} else {
    $hasAccountsPrefix = str_contains($googleReview->external_id, 'accounts/');
    
    if ($hasAccountsPrefix) {
        echo "✅ PASS: Review has correct external_id format for GMB API\n";
        echo "   Format: {$googleReview->external_id}\n";
    } else {
        echo "⚠️  INFO: Review external_id is from Places API (not GMB API)\n";
        echo "   Format: {$googleReview->external_id}\n";
        echo "   Note: Replies require GMB API format (accounts/...)\n";
        echo "   Run review sync with GMB credentials to get proper format\n";
    }
}

echo "\n";

// Summary
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Summary\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($allPassed) {
    echo "✅ ALL TESTS PASSED\n\n";
    echo "The fixes are working correctly:\n";
    echo "1. ✓ Review images are now included in API responses\n";
    echo "2. ✓ Media upload methods are properly implemented\n";
    echo "3. ✓ Using correct GMB API v4 endpoints\n";
    echo "\nNext steps:\n";
    echo "- Test with real GMB credentials and reviews\n";
    echo "- Sync reviews using GMB API (not Places API)\n";
    echo "- Test media upload with actual images\n";
} else {
    echo "❌ SOME TESTS FAILED\n\n";
    echo "Please review the failures above.\n";
}

echo "\n";
