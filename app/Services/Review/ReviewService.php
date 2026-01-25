<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

class ReviewService
{
    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $locationIds = $tenant->locations()->pluck('id');

        $with = ['location', 'response'];
        if (isset($filters['include']) && str_contains($filters['include'], 'sentiment')) {
            $with[] = 'sentiment';
        }

        $query = Review::query()
            ->whereIn('location_id', $locationIds)
            ->with($with);

        return $this->applyFilters($query, $filters);
    }

    public function listForLocation(Location $location, array $filters = []): LengthAwarePaginator
    {
        $with = ['response'];
        if (isset($filters['include']) && str_contains($filters['include'], 'sentiment')) {
            $with[] = 'sentiment';
        }

        $query = $location->reviews()->with($with)->getQuery();

        return $this->applyFilters($query, $filters);
    }

    protected function applyFilters(Builder $query, array $filters): LengthAwarePaginator
    {
        if (isset($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }

        if (isset($filters['rating'])) {
            $query->where('rating', $filters['rating']);
        }

        if (isset($filters['min_rating'])) {
            $query->where('rating', '>=', $filters['min_rating']);
        }

        if (isset($filters['has_response'])) {
            if ($filters['has_response'] === 'true' || $filters['has_response'] === true) {
                $query->whereHas('response');
            } else {
                $query->whereDoesntHave('response');
            }
        }

        // Filter by sentiment: AI sentiment if available, or rating-based fallback
        if (isset($filters['sentiment'])) {
            $sentimentValue = $filters['sentiment'];
            if (in_array($sentimentValue, ['positive', 'negative', 'neutral', 'mixed'])) {
                // If review has AI sentiment analysis, use it
                // Otherwise, fall back to rating-based sentiment
                $query->where(function ($q) use ($sentimentValue) {
                    $q->whereHas('sentiment', fn($sub) => $sub->where('sentiment', $sentimentValue))
                        ->orWhere(function ($fallback) use ($sentimentValue) {
                            $fallback->whereDoesntHave('sentiment');
                            if ($sentimentValue === 'negative') {
                                $fallback->where('rating', '<=', 3);
                            } elseif ($sentimentValue === 'positive') {
                                $fallback->where('rating', '>=', 4);
                            }
                        });
                });
            }
        }

        // Filter by minimum sentiment score
        if (isset($filters['min_sentiment_score'])) {
            $query->whereHas('sentiment', fn($q) => $q->where('sentiment_score', '>=', $filters['min_sentiment_score']));
        }

        if (isset($filters['search'])) {
            $query->where('content', 'like', "%{$filters['search']}%");
        }

        if (isset($filters['from'])) {
            $query->whereDate('published_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('published_at', '<=', $filters['to']);
        }

        // Sorting
        $sortField = $filters['sort'] ?? 'published_at';
        $sortDirection = $filters['direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    public function findForTenant(Tenant $tenant, int $reviewId): ?Review
    {
        $locationIds = $tenant->locations()->pluck('id');

        return Review::query()
            ->whereIn('location_id', $locationIds)
            ->with(['location', 'response.user'])
            ->find($reviewId);
    }

    public function getStats(Tenant $tenant, ?Location $location = null): array
    {
        if ($location) {
            $query = $location->reviews()->getQuery();
        } else {
            $locationIds = $tenant->locations()->pluck('id');
            $query = Review::query()->whereIn('location_id', $locationIds);
        }

        $totalReviews = $query->count();
        $averageRating = $totalReviews > 0 ? round((float) $query->avg('rating'), 1) : 0;

        // Rating distribution
        $ratingDistribution = [];
        for ($i = 5; $i >= 1; $i--) {
            $ratingDistribution[$i] = (clone $query)->where('rating', $i)->count();
        }

        // Stats by platform
        $byPlatform = [];
        $platforms = (clone $query)->distinct()->pluck('platform');
        foreach ($platforms as $platform) {
            $platformQuery = (clone $query)->where('platform', $platform);
            $byPlatform[$platform] = [
                'count' => $platformQuery->count(),
                'average_rating' => round((float) $platformQuery->avg('rating'), 1),
            ];
        }

        return [
            'total_reviews' => $totalReviews,
            'average_rating' => $averageRating,
            'rating_distribution' => $ratingDistribution,
            'by_platform' => $byPlatform,
        ];
    }

    public function createResponse(Review $review, User $user, array $data): ReviewResponse
    {
        return $review->response()->create([
            'user_id' => $user->id,
            'content' => $data['content'],
            'status' => $data['status'] ?? 'draft',
            'ai_generated' => $data['ai_generated'] ?? false,
        ]);
    }

    public function updateResponse(ReviewResponse $response, array $data): ReviewResponse
    {
        $response->update($data);

        return $response->fresh();
    }

    public function publishResponse(ReviewResponse $response): ReviewResponse
    {
        $review = $response->review;
        $location = $review->location;

        // Handle Google My Business responses (OAuth)
        if ($review->platform === 'google' || $review->platform === PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS) {
            $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);

            if ($credential && $credential->isValid()) {
                // Determine the review ID format
                // Local review external_id might be a hash (from Places API) or a real ID (from GMB API)
                // If it's a hash, we can't reply via API easily unless we stored the resource name
                // But if we synced via GMB API, external_id is the resource name.
                
                $reviewName = $review->external_id;
                
                // Basic check if it looks like a resource name (accounts/.../reviews/...)
                if (str_contains($reviewName, 'accounts/')) {
                    $success = app(\App\Services\Listing\GoogleMyBusinessService::class)
                        ->replyToReview($reviewName, $response->content, $credential->access_token);

                    if (!$success) {
                        throw new \Exception('Failed to publish response to Google My Business');
                    }
                    return $response->fresh();
                } else {
                     \Log::warning('Cannot reply to Google review: Invalid ID format (likely from Places API fallback)', ['review_id' => $review->id]);
                     // Fall through to mark as published locally but warn
                }
            }
        }

        // Handle Facebook responses
        if ($review->platform === PlatformCredential::PLATFORM_FACEBOOK) {
            // Get the correct credential for this review's Facebook page
            $credential = null;

            // First, try to get from location's facebook_page_id
            if ($location->hasFacebookPageId()) {
                $credential = PlatformCredential::where('tenant_id', $location->tenant_id)
                    ->where('platform', PlatformCredential::PLATFORM_FACEBOOK)
                    ->where('external_id', $location->facebook_page_id)
                    ->where('is_active', true)
                    ->first();
            }

            // Fallback: Use any active Facebook credential for this tenant
            if (!$credential) {
                $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_FACEBOOK);
            }

            if ($credential && $credential->isValid()) {
                $success = app(FacebookReviewService::class)->publishResponse($response, $credential);
                if (!$success) {
                    throw new \Exception('Failed to publish response to Facebook');
                }
            } else {
                throw new \Exception('No valid Facebook credentials found for this location');
            }
        }

        // Handle Google Play Store responses
        if ($review->platform === PlatformCredential::PLATFORM_GOOGLE_PLAY) {
            $success = app(GooglePlayStoreService::class)->replyToReview($review, $response->content);
            if (!$success) {
                throw new \Exception('Failed to publish response to Google Play Store');
            }
            // The service updates the response status and platform_synced, so we reload it
            return $response->fresh();
        }

        // Handle YouTube responses
        if ($review->platform === PlatformCredential::PLATFORM_YOUTUBE) {
            $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_YOUTUBE);
            if ($credential && $credential->isValid()) {
                $success = app(YouTubeReviewService::class)->replyToReview($review, $response->content, $credential);
                if (!$success) {
                    throw new \Exception('Failed to publish response to YouTube');
                }
                return $response->fresh();
            }
        }

        // For other platforms or if not published to platform, just mark as published locally
        if (!$response->isPublished()) {
            $response->publish();
        }

        return $response->fresh();
    }

    public function deleteResponse(ReviewResponse $response): void
    {
        $response->delete();
    }

    public function syncReviewsForLocation(Location $location): array
    {
        $counts = [
            'google' => 0,
            'facebook' => 0,
            'google_play' => 0,
            'youtube' => 0,
        ];

        // 1. Try Google My Business API (OAuth) first - It's better (All reviews + Replying)
        $gmbCredential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
        if ($gmbCredential && $gmbCredential->isValid()) {
             $counts['google'] = $this->syncGoogleReviewsViaApi($location, $gmbCredential);
        } elseif ($location->hasGooglePlaceId()) {
            // 2. Fallback to Google Places API (Public Key) - Limited to top 5
            $counts['google'] = $this->syncGoogleReviews($location);
        }

        // Sync Facebook reviews if location has Facebook page ID
        if ($location->hasFacebookPageId()) {
            $facebookCredential = PlatformCredential::where('tenant_id', $location->tenant_id)
                ->where('platform', PlatformCredential::PLATFORM_FACEBOOK)
                ->where('external_id', $location->facebook_page_id)
                ->where('is_active', true)
                ->first();

            if ($facebookCredential && $facebookCredential->isValid()) {
                $counts['facebook'] = app(FacebookReviewService::class)->syncFacebookReviews($location, $facebookCredential);
            }
        }

        // Sync Google Play Store reviews
        if ($location->hasGooglePlayPackageName()) {
            $counts['google_play'] = app(GooglePlayStoreService::class)->syncReviews($location);
        }

        // Sync YouTube reviews
        if ($location->hasYouTubeChannelId()) {
            $youtubeCredential = PlatformCredential::where('tenant_id', $location->tenant_id)
                ->where('platform', PlatformCredential::PLATFORM_YOUTUBE)
                ->where('is_active', true)
                ->first();

            if ($youtubeCredential && $youtubeCredential->isValid()) {
                $counts['youtube'] = app(YouTubeReviewService::class)->syncReviews($location, $youtubeCredential);
            }
        }

        // TODO: Add Yelp sync when API key is configured
        // if ($location->hasYelpBusinessId()) {
        //     $this->syncYelpReviews($location);
        // }

        $location->update(['reviews_synced_at' => now()]);

        return $counts;
    }

    protected function syncGoogleReviewsViaApi(Location $location, PlatformCredential $credential): int
    {
        // We need the location name (accounts/{id}/locations/{id})
        // If the location was synced via GMB, it might be in listing external_id or credential metadata
        // For now, let's assume the credential's metadata has the location name OR we need to find it.
        
        $listing = \App\Models\Listing::where('location_id', $location->id)
            ->where('platform', \App\Models\Listing::PLATFORM_GOOGLE_MY_BUSINESS)
            ->first();

        $locationName = $listing?->external_id ?? $credential->metadata['location_name'] ?? null;

        if (!$locationName) {
            \Log::warning('Cannot sync GMB reviews: Missing location name (external_id)', ['location_id' => $location->id]);
            return 0;
        }

        $service = app(\App\Services\Listing\GoogleMyBusinessService::class);
        $reviewsData = $service->getReviews($locationName, $credential->access_token);

        if (!$reviewsData || !isset($reviewsData['reviews'])) {
            return 0;
        }

        $syncedCount = 0;
        foreach ($reviewsData['reviews'] as $reviewData) {
            // Map GMB API format to DB
            $starRating = match($reviewData['starRating'] ?? '') {
                'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
                default => 0
            };

            if ($starRating === 0) continue;

            Review::updateOrCreate(
                [
                    'location_id' => $location->id,
                    'platform' => 'google', // Normalize to 'google'
                    'external_id' => $reviewData['name'], // Store the full resource name for replying
                ],
                [
                    'author_name' => $reviewData['reviewer']['displayName'] ?? 'Anonymous',
                    'author_image' => $reviewData['reviewer']['profilePhotoUrl'] ?? null,
                    'rating' => $starRating,
                    'content' => $reviewData['comment'] ?? null,
                    'published_at' => isset($reviewData['createTime'])
                        ? \Carbon\Carbon::parse($reviewData['createTime'])
                        : now(),
                    'metadata' => $reviewData,
                ]
            );
            $syncedCount++;
        }
        
        \Log::info('Google GMB API reviews synced', ['count' => $syncedCount, 'location_id' => $location->id]);
        return $syncedCount;
    }

    protected function syncGoogleReviews(Location $location): int
    {
        if (!$location->hasGooglePlaceId()) {
            return 0;
        }

        $apiKey = config('google.places.api_key');

        if (empty($apiKey)) {
            \Log::warning('Google Places API key not configured');

            return 0;
        }

        $placeId = $location->google_place_id;

        $response = Http::get(config('google.places.base_url') . '/details/json', [
            'place_id' => $placeId,
            'fields' => 'reviews',
            'key' => $apiKey,
        ]);

        if (!$response->successful()) {
            \Log::error('Google Places API error', [
                'location_id' => $location->id,
                'place_id' => $placeId,
                'status' => $response->status(),
                'error' => $response->json('error_message'),
            ]);

            return 0;
        }

        $reviews = $response->json('result.reviews', []);
        $syncedCount = 0;

        foreach ($reviews as $reviewData) {
            if (!isset($reviewData['rating'])) {
                continue;
            }

            Review::updateOrCreate(
                [
                    'location_id' => $location->id,
                    'platform' => 'google',
                    'external_id' => md5(($reviewData['author_name'] ?? '') . ($reviewData['time'] ?? '')),
                ],
                [
                    'author_name' => $reviewData['author_name'] ?? null,
                    'author_image' => $reviewData['profile_photo_url'] ?? null,
                    'rating' => $reviewData['rating'],
                    'content' => $reviewData['text'] ?? null,
                    'published_at' => isset($reviewData['time'])
                        ? \Carbon\Carbon::createFromTimestamp($reviewData['time'])
                        : now(),
                    'metadata' => $reviewData,
                ]
            );

            $syncedCount++;
        }

        \Log::info('Google reviews synced', [
            'location_id' => $location->id,
            'synced_count' => $syncedCount,
        ]);

        return $syncedCount;
    }
}
