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

        // Handle Facebook responses
        if ($review->platform === 'facebook') {
            $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_FACEBOOK);
            if ($credential && $credential->isValid()) {
                $success = app(FacebookReviewService::class)->publishResponse($response, $credential);
                if (!$success) {
                    throw new \Exception('Failed to publish response to Facebook');
                }
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

    public function syncReviewsForLocation(Location $location): void
    {
        if ($location->hasGooglePlaceId()) {
            $this->syncGoogleReviews($location);
        }

        // Sync Facebook reviews if location has Facebook page ID
        if ($location->hasFacebookPageId()) {
            $facebookCredential = PlatformCredential::where('tenant_id', $location->tenant_id)
                ->where('platform', PlatformCredential::PLATFORM_FACEBOOK)
                ->where('external_id', $location->facebook_page_id)
                ->where('is_active', true)
                ->first();

            if ($facebookCredential && $facebookCredential->isValid()) {
                app(FacebookReviewService::class)->syncFacebookReviews($location, $facebookCredential);
            }
        }

        // TODO: Add Yelp sync when API key is configured
        // if ($location->hasYelpBusinessId()) {
        //     $this->syncYelpReviews($location);
        // }

        $location->update(['reviews_synced_at' => now()]);
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
