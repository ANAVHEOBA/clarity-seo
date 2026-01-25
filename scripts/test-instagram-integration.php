<?php

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Instagram Integration...\n\n";

$tenant = App\Models\Tenant::first();
$credential = App\Models\PlatformCredential::where('tenant_id', $tenant->id)
    ->where('platform', 'facebook')
    ->first();

if (!$credential) {
    echo "❌ No Facebook credential found. Please connect Facebook first.\n";
    exit(1);
}

$pageId = $credential->getPageId();
$accessToken = $credential->metadata['page_access_token'] ?? $credential->access_token;

echo "Facebook Page ID: $pageId\n";

// 1. Get Linked Instagram Account
$url = "https://graph.facebook.com/v24.0/{$pageId}?fields=instagram_business_account,name";
$response = Http::get($url, ['access_token' => $accessToken]);

if (!$response->successful()) {
    echo "❌ Failed to fetch Page details: " . $response->status() . "\n";
    print_r($response->json());
    exit(1);
}

$data = $response->json();
$igAccount = $data['instagram_business_account'] ?? null;

if (!$igAccount) {
    echo "⚠️ No Instagram Business Account linked to this Facebook Page.\n";
    echo "Action Required: Go to Facebook Page Settings -> Linked Accounts -> Instagram and connect your professional account.\n";
    exit(0);
}

$igId = $igAccount['id'];
echo "✅ Found Linked Instagram ID: $igId\n";

// 2. Fetch Recent Media (Posts)
echo "\nFetching recent Instagram Media...\n";
$mediaUrl = "https://graph.facebook.com/v24.0/{$igId}/media?fields=id,caption,media_type,comments_count,timestamp&limit=5";
$mediaResponse = Http::get($mediaUrl, ['access_token' => $accessToken]);

if ($mediaResponse->successful()) {
    $mediaItems = $mediaResponse->json('data');
    echo "Found " . count($mediaItems) . " media items.\n";

    foreach ($mediaItems as $media) {
        echo "----------------------------------------\n";
        echo "Media ID: " . $media['id'] . "\n";
        echo "Caption: " . ($media['caption'] ?? 'No Caption') . "\n";
        echo "Comments: " . ($media['comments_count'] ?? 0) . "\n";

        // 3. Fetch Comments for this Media
        if (($media['comments_count'] ?? 0) > 0) {
            echo "   Fetching comments...\n";
            $commentsUrl = "https://graph.facebook.com/v24.0/{$media['id']}/comments?fields=id,text,username,timestamp";
            $commentsResponse = Http::get($commentsUrl, ['access_token' => $accessToken]);
            
            if ($commentsResponse->successful()) {
                foreach ($commentsResponse->json('data') as $comment) {
                    echo "   - [{$comment['username']}]: {$comment['text']}\n";
                }
            }
        }
    }
} else {
    echo "❌ Failed to fetch media.\n";
    print_r($mediaResponse->json());
}


