<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\ReviewResponse;
use Carbon\Carbon;
use Google\Client;
use Google\Service\AndroidPublisher;
use Illuminate\Support\Facades\Log;

class GooglePlayStoreService
{
    protected ?AndroidPublisher $publisherService = null;

    /**
     * Set the publisher service (primarily for testing).
     */
    public function setPublisherService(AndroidPublisher $service): self
    {
        $this->publisherService = $service;
        return $this;
    }

    /**
     * Sync reviews for a location from Google Play Store.
     *
     * @param Location $location
     * @return int Number of reviews synced
     */
    public function syncReviews(Location $location): int
    {
        if (!$location->hasGooglePlayPackageName()) {
            Log::warning('Google Play Sync: No package name for location', ['location_id' => $location->id]);
            return 0;
        }

        try {
            $service = $this->publisherService ?? $this->getAndroidPublisherServiceForLocation($location);

            if (!$service) {
                Log::error('Google Play Sync: No valid credentials found', ['tenant_id' => $location->tenant_id]);
                return 0;
            }

            $packageName = $location->google_play_package_name;
            
            $reviewsResponse = $service->reviews->listReviews($packageName);
            $syncedCount = 0;
            $triggerEvaluator = app(\App\Services\Automation\Triggers\TriggerEvaluator::class);

            $reviews = $reviewsResponse->getReviews() ?? [];

            foreach ($reviews as $playReview) {
                // $playReview is Google\Service\AndroidPublisher\Review
                $comment = $playReview->getComments()[0]->getUserComment();
                $starRating = $comment->getStarRating();
                $text = $comment->getText();
                $authorName = $playReview->getAuthorName();
                $reviewId = $playReview->getReviewId();
                
                // Timestamp from API is simpler: "seconds" and "nanos"
                $seconds = $comment->getLastModified()->getSeconds();
                $publishedAt = Carbon::createFromTimestamp($seconds);

                $review = Review::updateOrCreate(
                    [
                        'location_id' => $location->id,
                        'platform' => PlatformCredential::PLATFORM_GOOGLE_PLAY,
                        'external_id' => $reviewId,
                    ],
                    [
                        'author_name' => $authorName,
                        'rating' => $starRating,
                        'content' => $text,
                        'published_at' => $publishedAt,
                        'metadata' => [
                            'android_os_version' => $comment->getAndroidOsVersion(),
                            'app_version_code' => $comment->getAppVersionCode(),
                            'app_version_name' => $comment->getAppVersionName(),
                            'device' => $comment->getDevice(),
                            'device_metadata' => $comment->getDeviceMetadata(),
                        ],
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
                
                // If there's a developer reply
                $developerComment = $playReview->getComments()[1] ?? null; // Usually index 1 if reply exists
                if ($developerComment && $developerComment->getDeveloperComment()) {
                    $replyText = $developerComment->getDeveloperComment()->getText();
                    $replySeconds = $developerComment->getDeveloperComment()->getLastModified()->getSeconds();
                    
                    $review->response()->updateOrCreate(
                        ['review_id' => $review->id],
                        [
                            'content' => $replyText,
                            'status' => 'published', // It's already on the store
                            'published_at' => Carbon::createFromTimestamp($replySeconds),
                            'user_id' => null, // External reply
                            'platform_synced' => true,
                        ]
                    );
                }

                $syncedCount++;
            }
            
            // Update sync timestamp
            $location->update(['reviews_synced_at' => now()]);

            return $syncedCount;

        } catch (\Exception $e) {
            Log::error('Google Play Sync Failed', [
                'location_id' => $location->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Reply to a review on Google Play Store.
     *
     * @param Review $review
     * @param string $content
     * @return bool
     */
    public function replyToReview(Review $review, string $content): bool
    {
        if ($review->platform !== PlatformCredential::PLATFORM_GOOGLE_PLAY) {
            return false;
        }

        try {
            $location = $review->location;
            $service = $this->publisherService ?? $this->getAndroidPublisherServiceForLocation($location);

            if (!$service) {
                Log::error('Google Play Reply: No valid credentials', ['review_id' => $review->id]);
                return false;
            }

            $packageName = $location->google_play_package_name;
            $reviewId = $review->external_id;

            $replyRequest = new AndroidPublisher\ReviewsReplyRequest();
            $replyRequest->setReplyText($content);

            $service->reviews->reply($packageName, $reviewId, $replyRequest);

            // Update local response record
            $review->response()->updateOrCreate(
                ['review_id' => $review->id],
                [
                    'content' => $content,
                    'status' => 'published',
                    'published_at' => now(),
                    'platform_synced' => true,
                ]
            );

            return true;

        } catch (\Exception $e) {
            Log::error('Google Play Reply Failed', [
                'review_id' => $review->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function getAndroidPublisherServiceForLocation(Location $location): ?AndroidPublisher
    {
        $credential = PlatformCredential::getForTenant(
            $location->tenant, 
            PlatformCredential::PLATFORM_GOOGLE_PLAY
        );

        return $this->getAndroidPublisherService($credential);
    }

    protected function getAndroidPublisherService(?PlatformCredential $credential = null): ?AndroidPublisher
    {
        $client = new Client();
        $jsonKey = null;

        if ($credential && $credential->isValid()) {
            $jsonKey = $credential->access_token;
        } else {
            // Fallback to env/config
            $jsonKey = config('google.play_store.service_account_json');
        }

        if (empty($jsonKey)) {
            return null;
        }

        // Handle Base64 encoded JSON
        if (str_starts_with($jsonKey, 'base64:')) {
            $jsonKey = base64_decode(substr($jsonKey, 7));
        }
        
        // If it looks like a path or JSON
        if (str_starts_with(trim($jsonKey), '{')) {
            $client->setAuthConfig(json_decode($jsonKey, true));
        } else {
             // Assume path if not JSON
            $client->setAuthConfig($jsonKey);
        }

        $client->addScope(AndroidPublisher::ANDROIDPUBLISHER);

        return new AndroidPublisher($client);
    }
}
