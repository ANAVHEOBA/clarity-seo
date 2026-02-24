<?php

declare(strict_types=1);

use App\Models\AppleAppStoreAccount;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const APPLE_TEST_PRIVATE_KEY_REVIEW = <<<'KEY'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIDorL4c3Wu+B8BbiBxErio91K/b4p6J+2479nu/2rLoboAoGCCqGSM49
AwEHoUQDQgAE1V9v121umEW3LFAMj8W/bj8OxkW0x+ym1UNuW0Ng6A6ekXCqYFWp
QvvJ/jpTeFc5q7y36GPkhahmh9aOSY7gIA==
-----END EC PRIVATE KEY-----
KEY;

describe('Apple App Store reviews', function () {
    it('syncs apple app store reviews for a location', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'apple_app_store_app_id' => '6758574978',
        ]);

        AppleAppStoreAccount::create([
            'tenant_id' => $tenant->id,
            'name' => 'Apple Key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '645X2P2WBB',
            'private_key' => APPLE_TEST_PRIVATE_KEY_REVIEW,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.appstoreconnect.apple.com/v1/apps/6758574978/customerReviews*' => Http::response([
                'data' => [
                    [
                        'type' => 'customerReviews',
                        'id' => 'review-1001',
                        'attributes' => [
                            'rating' => 5,
                            'title' => 'Great app',
                            'body' => 'Love this product',
                            'reviewerNickname' => 'HappyUser',
                            'createdDate' => '2026-02-24T10:00:00Z',
                        ],
                        'relationships' => [
                            'response' => [
                                'data' => [
                                    'type' => 'customerReviewResponses',
                                    'id' => 'response-999',
                                ],
                            ],
                        ],
                    ],
                ],
                'included' => [
                    [
                        'type' => 'customerReviewResponses',
                        'id' => 'response-999',
                        'attributes' => [
                            'responseBody' => 'Thanks for your feedback!',
                            'lastModifiedDate' => '2026-02-24T11:00:00Z',
                        ],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/reviews/sync");
        $response->assertStatus(202);

        $this->assertDatabaseHas('reviews', [
            'location_id' => $location->id,
            'platform' => 'apple_app_store',
            'external_id' => 'review-1001',
            'author_name' => 'HappyUser',
            'rating' => 5,
        ]);

        $review = Review::where('location_id', $location->id)
            ->where('platform', 'apple_app_store')
            ->where('external_id', 'review-1001')
            ->first();

        expect($review)->not->toBeNull();

        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'content' => 'Thanks for your feedback!',
            'status' => 'published',
            'platform_synced' => 1,
        ]);
    });

    it('publishes response to apple app store', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();

        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'apple_app_store_app_id' => '6758574978',
        ]);

        AppleAppStoreAccount::create([
            'tenant_id' => $tenant->id,
            'name' => 'Apple Key',
            'issuer_id' => '3d24e14c-e344-4c39-bd2d-7dd0c414f476',
            'key_id' => '645X2P2WBB',
            'private_key' => APPLE_TEST_PRIVATE_KEY_REVIEW,
            'is_active' => true,
        ]);

        $review = Review::factory()->create([
            'location_id' => $location->id,
            'platform' => 'apple_app_store',
            'external_id' => 'review-2002',
            'metadata' => [
                'apple_review_id' => 'review-2002',
                'apple_app_store_app_id' => '6758574978',
            ],
        ]);

        $reviewResponse = ReviewResponse::factory()->draft()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'content' => 'Thank you for your review!',
            'status' => 'draft',
        ]);

        Http::fake([
            'https://api.appstoreconnect.apple.com/v1/customerReviewResponses' => Http::response([
                'data' => [
                    'type' => 'customerReviewResponses',
                    'id' => 'response-2002',
                ],
            ], 201),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response/publish");
        $response->assertOk();

        $reviewResponse->refresh();
        expect($reviewResponse->status)->toBe('published');
        expect($reviewResponse->platform_synced)->toBeTrue();

        $review->refresh();
        expect($review->metadata['apple_response_id'] ?? null)->toBe('response-2002');
    });
});
