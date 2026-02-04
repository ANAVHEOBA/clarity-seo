#!/usr/bin/env php
<?php

/**
 * Verify Review Duplicates Fix
 * 
 * This script checks for duplicate reviews and validates the fix.
 * Run: php scripts/verify-review-duplicates.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Review;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         Review Duplicates Verification Script                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Check 1: NULL external_ids
echo "✓ Checking for NULL external_ids...\n";
$nullCount = Review::whereNull('external_id')->count();
if ($nullCount > 0) {
    echo "  ❌ FAIL: Found {$nullCount} reviews with NULL external_id\n";
    exit(1);
} else {
    echo "  ✅ PASS: No reviews with NULL external_id\n";
}

// Check 2: Duplicate reviews
echo "\n✓ Checking for duplicate reviews...\n";
$duplicates = DB::table('reviews')
    ->select('location_id', 'platform', 'external_id', DB::raw('COUNT(*) as count'))
    ->groupBy('location_id', 'platform', 'external_id')
    ->having('count', '>', 1)
    ->get();

if ($duplicates->count() > 0) {
    echo "  ❌ FAIL: Found {$duplicates->count()} duplicate review groups\n";
    foreach ($duplicates as $dup) {
        echo "    - Location: {$dup->location_id}, Platform: {$dup->platform}, Count: {$dup->count}\n";
    }
    exit(1);
} else {
    echo "  ✅ PASS: No duplicate reviews found\n";
}

// Check 3: External ID format validation
echo "\n✓ Checking external_id formats...\n";
$facebookReviews = Review::where('platform', 'facebook')->get();
$googleReviews = Review::where('platform', 'google')->get();

$facebookFormatIssues = 0;
foreach ($facebookReviews as $review) {
    // Facebook should have either numeric ID or fb_ prefix
    if (!is_numeric($review->external_id) && !str_starts_with($review->external_id, 'fb_')) {
        $facebookFormatIssues++;
    }
}

if ($facebookFormatIssues > 0) {
    echo "  ⚠️  WARNING: {$facebookFormatIssues} Facebook reviews have unexpected external_id format\n";
} else {
    echo "  ✅ PASS: All Facebook reviews have valid external_id format\n";
}

// Check 4: Statistics
echo "\n✓ Review Statistics:\n";
$totalReviews = Review::count();
$byPlatform = Review::select('platform', DB::raw('COUNT(*) as count'))
    ->groupBy('platform')
    ->get();

echo "  Total reviews: {$totalReviews}\n";
foreach ($byPlatform as $platform) {
    echo "  - {$platform->platform}: {$platform->count}\n";
}

// Check 5: Unique constraint test
echo "\n✓ Testing unique constraint enforcement...\n";
try {
    $testReview = Review::first();
    if ($testReview) {
        DB::table('reviews')->insert([
            'location_id' => $testReview->location_id,
            'platform' => $testReview->platform,
            'external_id' => $testReview->external_id,
            'author_name' => 'Test',
            'rating' => 5,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "  ❌ FAIL: Unique constraint not working - duplicate was inserted\n";
        // Clean up
        DB::table('reviews')
            ->where('author_name', 'Test')
            ->where('location_id', $testReview->location_id)
            ->delete();
        exit(1);
    } else {
        echo "  ⚠️  SKIP: No reviews to test with\n";
    }
} catch (\Illuminate\Database\QueryException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE constraint failed') || 
        str_contains($e->getMessage(), 'Duplicate entry')) {
        echo "  ✅ PASS: Unique constraint is working correctly\n";
    } else {
        echo "  ❌ FAIL: Unexpected error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    ✅ ALL CHECKS PASSED                        ║\n";
echo "║         Review duplication fix is working correctly!          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";

exit(0);
