<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\AppleAppStoreAccount;
use App\Models\AppleAppStoreApp;
use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Services\Listing\AppleAppStoreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppleAppStoreReviewService
{
    public function __construct(
        private readonly AppleAppStoreService $appleService,
    ) {}

    public function syncReviews(Location $location): int
    {
        if (! $location->hasAppleAppStoreAppId()) {
            return 0;
        }

        $appStoreAppId = (string) $location->apple_app_store_app_id;
        $account = $this->resolveAccountForLocation($location, $appStoreAppId);

        if (! $account || ! $account->is_active) {
            Log::warning('Apple App Store Sync: no active account found', [
                'location_id' => $location->id,
                'tenant_id' => $location->tenant_id,
                'app_store_app_id' => $appStoreAppId,
            ]);
            return 0;
        }

        $tokenResult = $this->appleService->validateAccountForApi($account);
        if (! $tokenResult['valid'] || empty($tokenResult['jwt'])) {
            Log::error('Apple App Store Sync: invalid account token', [
                'location_id' => $location->id,
                'reason' => $tokenResult['reason'],
            ]);
            return 0;
        }

        $baseUrl = $this->appleService->getApiBaseUrl();
        $jwt = (string) $tokenResult['jwt'];

        $response = Http::withToken($jwt)
            ->acceptJson()
            ->get("{$baseUrl}/v1/apps/{$appStoreAppId}/customerReviews", [
                'limit' => 200,
                'sort' => '-createdDate',
                'include' => 'response',
            ]);

        if (! $response->successful()) {
            Log::error('Apple App Store Sync failed', [
                'location_id' => $location->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return 0;
        }

        $payload = $response->json();
        $reviews = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $included = is_array($payload['included'] ?? null) ? $payload['included'] : [];

        $responsesById = [];
        foreach ($included as $resource) {
            $resourceId = $resource['id'] ?? null;
            $resourceType = $resource['type'] ?? null;
            if (is_string($resourceId) && is_string($resourceType) && str_contains($resourceType, 'customerReviewResponse')) {
                $responsesById[$resourceId] = $resource;
            }
        }

        $syncedCount = 0;

        foreach ($reviews as $reviewData) {
            $reviewId = $reviewData['id'] ?? null;
            if (! is_string($reviewId) || $reviewId === '') {
                continue;
            }

            $attributes = is_array($reviewData['attributes'] ?? null) ? $reviewData['attributes'] : [];
            $rating = (int) ($attributes['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $content = $attributes['body'] ?? $attributes['title'] ?? null;

            $review = Review::updateOrCreate(
                [
                    'location_id' => $location->id,
                    'platform' => PlatformCredential::PLATFORM_APPLE_APP_STORE,
                    'external_id' => $reviewId,
                ],
                [
                    'author_name' => $attributes['reviewerNickname']
                        ?? $attributes['nickname']
                        ?? 'App Store User',
                    'rating' => $rating,
                    'content' => is_string($content) ? $content : null,
                    'published_at' => $attributes['createdDate'] ?? $attributes['lastModifiedDate'] ?? now(),
                    'metadata' => [
                        'apple_review_id' => $reviewId,
                        'apple_app_store_app_id' => $appStoreAppId,
                        'raw' => $reviewData,
                    ],
                ]
            );

            $responseId = $reviewData['relationships']['response']['data']['id'] ?? null;
            if (! is_string($responseId) && isset($review->metadata['apple_response_id'])) {
                $responseId = $review->metadata['apple_response_id'];
            }

            if (is_string($responseId) && isset($responsesById[$responseId])) {
                $respAttributes = is_array($responsesById[$responseId]['attributes'] ?? null)
                    ? $responsesById[$responseId]['attributes']
                    : [];

                $replyContent = $respAttributes['responseBody'] ?? $respAttributes['body'] ?? null;

                if (is_string($replyContent) && $replyContent !== '') {
                    $review->response()->updateOrCreate(
                        ['review_id' => $review->id],
                        [
                            'user_id' => null,
                            'content' => $replyContent,
                            'status' => 'published',
                            'published_at' => $respAttributes['lastModifiedDate'] ?? now(),
                            'platform_synced' => true,
                            'ai_generated' => false,
                        ]
                    );
                }

                $metadata = is_array($review->metadata) ? $review->metadata : [];
                $metadata['apple_response_id'] = $responseId;
                $review->update(['metadata' => $metadata]);
            }

            $syncedCount++;
        }

        return $syncedCount;
    }

    public function replyToReview(Review $review, string $content): bool
    {
        if ($review->platform !== PlatformCredential::PLATFORM_APPLE_APP_STORE) {
            return false;
        }

        $location = $review->location;
        if (! $location->hasAppleAppStoreAppId()) {
            return false;
        }

        $appStoreAppId = (string) $location->apple_app_store_app_id;
        $account = $this->resolveAccountForLocation($location, $appStoreAppId);

        if (! $account || ! $account->is_active) {
            return false;
        }

        $tokenResult = $this->appleService->validateAccountForApi($account);
        if (! $tokenResult['valid'] || empty($tokenResult['jwt'])) {
            return false;
        }

        $reviewId = (string) (($review->metadata['apple_review_id'] ?? null) ?: $review->external_id);
        if ($reviewId === '') {
            return false;
        }

        $baseUrl = $this->appleService->getApiBaseUrl();
        $jwt = (string) $tokenResult['jwt'];

        $metadata = is_array($review->metadata) ? $review->metadata : [];
        $existingResponseId = $metadata['apple_response_id'] ?? null;

        if (is_string($existingResponseId) && $existingResponseId !== '') {
            $response = Http::withToken($jwt)
                ->acceptJson()
                ->patch("{$baseUrl}/v1/customerReviewResponses/{$existingResponseId}", [
                    'data' => [
                        'type' => 'customerReviewResponses',
                        'id' => $existingResponseId,
                        'attributes' => [
                            'responseBody' => $content,
                        ],
                    ],
                ]);
        } else {
            $response = Http::withToken($jwt)
                ->acceptJson()
                ->post("{$baseUrl}/v1/customerReviewResponses", [
                    'data' => [
                        'type' => 'customerReviewResponses',
                        'attributes' => [
                            'responseBody' => $content,
                        ],
                        'relationships' => [
                            'review' => [
                                'data' => [
                                    'type' => 'customerReviews',
                                    'id' => $reviewId,
                                ],
                            ],
                        ],
                    ],
                ]);
        }

        if (! $response->successful()) {
            Log::error('Apple App Store reply failed', [
                'review_id' => $review->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
            return false;
        }

        $responseId = $response->json('data.id');
        if (is_string($responseId) && $responseId !== '') {
            $metadata['apple_response_id'] = $responseId;
            $review->update(['metadata' => $metadata]);
        }

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
    }

    private function resolveAccountForLocation(Location $location, string $appStoreAppId): ?AppleAppStoreAccount
    {
        $mappedApp = AppleAppStoreApp::query()
            ->where('tenant_id', $location->tenant_id)
            ->where('app_store_id', $appStoreAppId)
            ->where('is_active', true)
            ->first();

        if ($mappedApp && $mappedApp->apple_app_store_account_id) {
            $mappedAccount = AppleAppStoreAccount::query()
                ->where('id', $mappedApp->apple_app_store_account_id)
                ->where('tenant_id', $location->tenant_id)
                ->where('is_active', true)
                ->first();

            if ($mappedAccount) {
                return $mappedAccount;
            }
        }

        return AppleAppStoreAccount::query()
            ->where('tenant_id', $location->tenant_id)
            ->where('is_active', true)
            ->first();
    }
}
