<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\ReviewResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramReviewService
{
    protected string $baseUrl;

    protected string $graphVersion;

    public function __construct()
    {
        $this->baseUrl = config('facebook.base_url', 'https://graph.facebook.com');
        $this->graphVersion = config('facebook.graph_version', 'v24.0');
    }

    protected function apiUrl(string $endpoint): string
    {
        return "{$this->baseUrl}/{$this->graphVersion}/{$endpoint}";
    }

    public function syncInstagramReviews(Location $location, PlatformCredential $credential): int
    {
        $pageId = $credential->getPageId();
        $accessToken = $credential->metadata['page_access_token'] ?? $credential->access_token;

        if (!$pageId) {
            Log::error('No Facebook Page ID found for Instagram sync', ['credential_id' => $credential->id]);
            return 0;
        }

        // 1. Get Linked Instagram Account ID
        $igId = $this->getInstagramAccountId($pageId, $accessToken);
        
        if (!$igId) {
            Log::warning('No linked Instagram Business Account found', ['page_id' => $pageId]);
            return 0;
        }

        // 2. Fetch Media (Posts)
        $mediaItems = $this->getRecentMedia($igId, $accessToken);
        
        if (empty($mediaItems)) {
            return 0;
        }

        $syncedCount = 0;
        $triggerEvaluator = app(\App\Services\Automation\Triggers\TriggerEvaluator::class);

        foreach ($mediaItems as $media) {
            // Only process if there are comments
            if (($media['comments_count'] ?? 0) > 0) {
                $comments = $this->getMediaComments($media['id'], $accessToken);
                
                foreach ($comments as $comment) {
                    // Save as Review
                    $review = Review::updateOrCreate(
                        [
                            'location_id' => $location->id,
                            'platform' => PlatformCredential::PLATFORM_INSTAGRAM,
                            'external_id' => $comment['id'],
                        ],
                        [
                            'author_name' => $comment['username'] ?? 'Instagram User',
                            'rating' => 0, // Instagram comments don't have ratings
                            'content' => $comment['text'] ?? '',
                            'published_at' => isset($comment['timestamp']) 
                                ? \Carbon\Carbon::parse($comment['timestamp']) 
                                : now(),
                            'metadata' => [
                                'media_id' => $media['id'],
                                'media_type' => $media['media_type'] ?? 'unknown',
                                'media_url' => $media['media_url'] ?? null,
                                'permalink' => $media['permalink'] ?? null,
                            ]
                        ]
                    );
                    
                    // Trigger automation workflows for new reviews
                    if ($review->wasRecentlyCreated) {
                        try {
                            $triggerEvaluator->handleReviewReceived($review);
                        } catch (\Exception $e) {
                            Log::error('Failed to trigger automation for review', [
                                'review_id' => $review->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    $syncedCount++;
                }
            }
        }

        Log::info('Instagram reviews (comments) synced', [
            'location_id' => $location->id,
            'count' => $syncedCount
        ]);

        return $syncedCount;
    }

    public function publishResponse(ReviewResponse $response, PlatformCredential $credential): bool
    {
        $review = $response->review;
        $accessToken = $credential->metadata['page_access_token'] ?? $credential->access_token;
        $commentId = $review->external_id;

        try {
            // Reply to a comment: POST /{comment_id}/replies
            $url = $this->apiUrl("{$commentId}/replies");
            
            $httpResponse = Http::post($url, [
                'access_token' => $accessToken,
                'message' => $response->content,
            ]);

            if (!$httpResponse->successful()) {
                Log::error('Instagram API error: Failed to publish reply', [
                    'review_id' => $review->id,
                    'error' => $httpResponse->json(),
                ]);
                return false;
            }

            $response->publish();
            return true;

        } catch (ConnectionException $e) {
            Log::error('Instagram connection error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function getInstagramAccountId(string $pageId, string $accessToken): ?string
    {
        try {
            $response = Http::get($this->apiUrl($pageId), [
                'fields' => 'instagram_business_account',
                'access_token' => $accessToken
            ]);

            if ($response->successful()) {
                return $response->json('instagram_business_account.id');
            }
        } catch (\Exception $e) {
            Log::error('Failed to get IG Account ID', ['error' => $e->getMessage()]);
        }
        return null;
    }

    protected function getRecentMedia(string $igId, string $accessToken): array
    {
        try {
            $response = Http::get($this->apiUrl("{$igId}/media"), [
                'fields' => 'id,caption,media_type,media_url,permalink,comments_count,timestamp',
                'limit' => 20, // Check last 20 posts
                'access_token' => $accessToken
            ]);

            return $response->successful() ? $response->json('data', []) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getMediaComments(string $mediaId, string $accessToken): array
    {
        try {
            $response = Http::get($this->apiUrl("{$mediaId}/comments"), [
                'fields' => 'id,text,username,timestamp',
                'access_token' => $accessToken
            ]);

            return $response->successful() ? $response->json('data', []) : [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
