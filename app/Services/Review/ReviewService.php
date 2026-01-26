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
use Illuminate\Support\Facades\Log;

class ReviewService
{
    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $locationIds = $tenant->locations()->pluck('id');
        $with = ['location', 'response'];
        if (isset($filters['include']) && str_contains($filters['include'], 'sentiment')) {
            $with[] = 'sentiment';
        }
        $query = Review::query()->whereIn('location_id', $locationIds)->with($with);
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
            $platform = $filters['platform'];
            // Normalize platform names
            if ($platform === PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS) {
                $platform = 'google';
            }
            $query->where('platform', $platform);
        }

        if (isset($filters['rating'])) {
        if (isset($filters['min_rating'])) $query->where('rating', '>=', $filters['min_rating']);
        if (isset($filters['has_response'])) {
            $filters['has_response'] === 'true' || $filters['has_response'] === true ? $query->whereHas('response') : $query->whereDoesntHave('response');
        }
        if (isset($filters['sentiment'])) {
            $sentimentValue = $filters['sentiment'];
            $query->where(function ($q) use ($sentimentValue) {
                $q->whereHas('sentiment', fn($sub) => $sub->where('sentiment', $sentimentValue))
                    ->orWhere(function ($fallback) use ($sentimentValue) {
                        $fallback->whereDoesntHave('sentiment');
                        if ($sentimentValue === 'negative') $fallback->where('rating', '<=', 3);
                        elseif ($sentimentValue === 'positive') $fallback->where('rating', '>=', 4);
                    });
            });
        }
        if (isset($filters['search'])) $query->where('content', 'like', "%{$filters['search']}%");
        if (isset($filters['from'])) $query->whereDate('published_at', '>=', $filters['from']);
        if (isset($filters['to'])) $query->whereDate('published_at', '<=', $filters['to']);

        $sortField = $filters['sort'] ?? 'published_at';
        $sortDirection = $filters['direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);
        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function findForTenant(Tenant $tenant, int $reviewId): ?Review
    {
        $locationIds = $tenant->locations()->pluck('id');
        return Review::query()->whereIn('location_id', $locationIds)->with(['location', 'response.user'])->find($reviewId);
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

        $ratingDistribution = [];
        for ($i = 5; $i >= 1; $i--) {
            $ratingDistribution[$i] = (clone $query)->where('rating', $i)->count();
        }

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
        return $review->response()->create(['user_id' => $user->id, 'content' => $data['content'], 'status' => $data['status'] ?? 'draft', 'ai_generated' => $data['ai_generated'] ?? false]);
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

        // Google
        if ($review->platform === 'google' || $review->platform === PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS) {
            $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
            if ($credential) {
                $accessToken = app(\App\Services\Listing\GoogleMyBusinessService::class)->ensureValidToken($credential);
                if ($accessToken && str_contains((string)$review->external_id, 'accounts/')) {
                    if (app(\App\Services\Listing\GoogleMyBusinessService::class)->replyToReview($review->external_id, $response->content, $accessToken)) {
                        return $response->fresh();
                    }
                    throw new \Exception('Failed to publish response to Google My Business');
                }
            }
        }

        // Facebook
        if ($review->platform === PlatformCredential::PLATFORM_FACEBOOK) {
            $credential = PlatformCredential::where('tenant_id', $location->tenant_id)->where('platform', PlatformCredential::PLATFORM_FACEBOOK)->where('external_id', $location->facebook_page_id)->first()
                ?? PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_FACEBOOK);
            if ($credential && $credential->isValid()) {
                if (!app(FacebookReviewService::class)->publishResponse($response, $credential)) throw new \Exception('Failed to publish response to Facebook');
            }
        }

        // Instagram
        if ($review->platform === PlatformCredential::PLATFORM_INSTAGRAM) {
            $credential = PlatformCredential::where('tenant_id', $location->tenant_id)->where('platform', PlatformCredential::PLATFORM_FACEBOOK)->where('external_id', $location->facebook_page_id)->first()
                ?? PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_FACEBOOK);
            if ($credential && $credential->isValid()) {
                if (!app(InstagramReviewService::class)->publishResponse($response, $credential)) throw new \Exception('Failed to publish response to Instagram');
            }
        }

        // Google Play
        if ($review->platform === PlatformCredential::PLATFORM_GOOGLE_PLAY) {
            if (!app(GooglePlayStoreService::class)->replyToReview($review, $response->content)) throw new \Exception('Failed to publish response to Google Play Store');
            return $response->fresh();
        }

        // YouTube
        if ($review->platform === PlatformCredential::PLATFORM_YOUTUBE) {
            $credential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_YOUTUBE);
            if ($credential && $credential->isValid()) {
                if (!app(YouTubeReviewService::class)->replyToReview($review, $response->content, $credential)) throw new \Exception('Failed to publish response to YouTube');
                return $response->fresh();
            }
        }

        if (!$response->isPublished()) $response->publish();
        return $response->fresh();
    }

    public function deleteResponse(ReviewResponse $response): void
    {
        $response->delete();
    }

    public function syncReviewsForLocation(Location $location): array
    {
        $counts = ['google' => 0, 'facebook' => 0, 'instagram' => 0, 'google_play' => 0, 'youtube' => 0];
        
        // Google
        $gmbCredential = PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_GOOGLE_MY_BUSINESS);
        $accessToken = $gmbCredential ? app(\App\Services\Listing\GoogleMyBusinessService::class)->ensureValidToken($gmbCredential) : null;

        if ($accessToken) $counts['google'] = $this->syncGoogleReviewsViaApi($location, $gmbCredential, $accessToken);
        elseif ($location->hasGooglePlaceId()) $counts['google'] = $this->syncGoogleReviews($location);

        // Facebook & Instagram (Both use Facebook Credential)
        if ($location->hasFacebookPageId()) {
            $fbCred = PlatformCredential::where('tenant_id', $location->tenant_id)->where('platform', 'facebook')->where('external_id', $location->facebook_page_id)->first()
                ?? PlatformCredential::getForTenant($location->tenant, PlatformCredential::PLATFORM_FACEBOOK);
            
            if ($fbCred && $fbCred->isValid()) {
                $counts['facebook'] = app(FacebookReviewService::class)->syncFacebookReviews($location, $fbCred);
                $counts['instagram'] = app(InstagramReviewService::class)->syncInstagramReviews($location, $fbCred);
            }
        }

        if ($location->hasGooglePlayPackageName()) $counts['google_play'] = app(GooglePlayStoreService::class)->syncReviews($location);
        
        if ($location->hasYouTubeChannelId()) {
            $ytCred = PlatformCredential::where('tenant_id', $location->tenant_id)->where('platform', 'youtube')->where('is_active', true)->first();
            if ($ytCred && $ytCred->isValid()) $counts['youtube'] = app(YouTubeReviewService::class)->syncReviews($location, $ytCred);
        }

        $location->update(['reviews_synced_at' => now()]);
        return $counts;
    }

    protected function syncGoogleReviewsViaApi(Location $location, PlatformCredential $credential, string $accessToken): int
    {
        $listing = \App\Models\Listing::where('location_id', $location->id)->where('platform', 'google_my_business')->first();
        $locationName = $listing?->external_id ?? $credential->metadata['location_name'] ?? null;

        if (!$locationName) return 0;

        $reviewsData = app(\App\Services\Listing\GoogleMyBusinessService::class)->getReviews($locationName, $accessToken);
        if (!$reviewsData || !isset($reviewsData['reviews'])) return 0;

        $syncedCount = 0;
        foreach ($reviewsData['reviews'] as $reviewData) {
            $starRating = match($reviewData['starRating'] ?? '') { 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, default => 0 };
            if ($starRating === 0) continue;

            Review::updateOrCreate(
                ['location_id' => $location->id, 'platform' => 'google', 'external_id' => $reviewData['name']],
                ['author_name' => $reviewData['reviewer']['displayName'] ?? 'Anonymous', 'author_image' => $reviewData['reviewer']['profilePhotoUrl'] ?? null, 'rating' => $starRating, 'content' => $reviewData['comment'] ?? null, 'published_at' => isset($reviewData['createTime']) ? \Carbon\Carbon::parse($reviewData['createTime']) : now(), 'metadata' => $reviewData]
            );
            $syncedCount++;
        }
        return $syncedCount;
    }

    protected function syncGoogleReviews(Location $location): int
    {
        $apiKey = config('google.places.api_key');
        if (empty($apiKey) || !$location->hasGooglePlaceId()) return 0;

        $response = Http::get(config('google.places.base_url') . '/details/json', ['place_id' => $location->google_place_id, 'fields' => 'reviews', 'key' => $apiKey]);
        if (!$response->successful()) return 0;

        $reviews = $response->json('result.reviews', []);
        $syncedCount = 0;
        foreach ($reviews as $reviewData) {
            if (!isset($reviewData['rating'])) continue;
            Review::updateOrCreate(
                ['location_id' => $location->id, 'platform' => 'google', 'external_id' => md5(($reviewData['author_name'] ?? '') . ($reviewData['time'] ?? ''))],
                ['author_name' => $reviewData['author_name'] ?? null, 'author_image' => $reviewData['profile_photo_url'] ?? null, 'rating' => $reviewData['rating'], 'content' => $reviewData['text'] ?? null, 'published_at' => isset($reviewData['time']) ? \Carbon\Carbon::createFromTimestamp($reviewData['time']) : now(), 'metadata' => $reviewData]
            );
            $syncedCount++;
        }
        return $syncedCount;
    }
}