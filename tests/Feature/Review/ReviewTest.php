<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('Review Listing', function () {
    it('lists all reviews for a tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        Review::factory()->count(5)->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertSuccessful();
        $response->assertJsonCount(5, 'data');
    });

    it('requires authentication to list reviews', function () {
        $tenant = Tenant::factory()->create();

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertUnauthorized();
    });

    it('requires tenant membership to list reviews', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertForbidden();
    });

    it('only lists reviews for locations in the tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $otherTenant = Tenant::factory()->create();

        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);

        Review::factory()->count(3)->create(['location_id' => $location->id]);
        Review::factory()->count(5)->create(['location_id' => $otherLocation->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertSuccessful();
        $response->assertJsonCount(3, 'data');
    });

    it('supports pagination', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        Review::factory()->count(25)->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?per_page=10");

        $response->assertSuccessful();
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.total', 25);
    });

    it('lists reviews for a specific location', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location1 = Location::factory()->create(['tenant_id' => $tenant->id]);
        $location2 = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(3)->create(['location_id' => $location1->id]);
        Review::factory()->count(5)->create(['location_id' => $location2->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/locations/{$location1->id}/reviews");

        $response->assertSuccessful();
        $response->assertJsonCount(3, 'data');
    });
});

describe('Review Filtering', function () {
    it('filters reviews by platform', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(3)->create(['location_id' => $location->id, 'platform' => 'google']);
        Review::factory()->count(2)->create(['location_id' => $location->id, 'platform' => 'yelp']);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?platform=google");

        $response->assertSuccessful();
        $response->assertJsonCount(3, 'data');
    });

    it('filters reviews by rating', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(3)->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->count(2)->create(['location_id' => $location->id, 'rating' => 4]);
        Review::factory()->count(4)->create(['location_id' => $location->id, 'rating' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?rating=5");

        $response->assertSuccessful();
        $response->assertJsonCount(3, 'data');
    });

    it('filters reviews by minimum rating', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(2)->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->count(3)->create(['location_id' => $location->id, 'rating' => 4]);
        Review::factory()->count(4)->create(['location_id' => $location->id, 'rating' => 2]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?min_rating=4");

        $response->assertSuccessful();
        $response->assertJsonCount(5, 'data');
    });

    it('filters reviews by response status', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $respondedReview = Review::factory()->create(['location_id' => $location->id]);
        ReviewResponse::factory()->create(['review_id' => $respondedReview->id]);

        Review::factory()->count(3)->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?has_response=false");

        $response->assertSuccessful();
        $response->assertJsonCount(3, 'data');
    });

    it('filters negative reviews (rating <= 3)', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(2)->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->count(3)->create(['location_id' => $location->id, 'rating' => 2]);
        Review::factory()->count(1)->create(['location_id' => $location->id, 'rating' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?sentiment=negative");

        $response->assertSuccessful();
        $response->assertJsonCount(4, 'data');
    });

    it('searches reviews by content', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->create([
            'location_id' => $location->id,
            'content' => 'Great pizza, best in town!',
        ]);
        Review::factory()->create([
            'location_id' => $location->id,
            'content' => 'Terrible service, never coming back.',
        ]);
        Review::factory()->create([
            'location_id' => $location->id,
            'content' => 'Amazing pizza delivery!',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?search=pizza");

        $response->assertSuccessful();
        $response->assertJsonCount(2, 'data');
    });

    it('filters reviews by date range', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->create([
            'location_id' => $location->id,
            'published_at' => now()->subDays(5),
        ]);
        Review::factory()->create([
            'location_id' => $location->id,
            'published_at' => now()->subDays(15),
        ]);
        Review::factory()->create([
            'location_id' => $location->id,
            'published_at' => now()->subDays(30),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?from=".now()->subDays(20)->toDateString());

        $response->assertSuccessful();
        $response->assertJsonCount(2, 'data');
    });
});

describe('Review Details', function () {
    it('returns review details', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create([
            'location_id' => $location->id,
            'author_name' => 'John Doe',
            'rating' => 5,
            'content' => 'Excellent service!',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}");

        $response->assertSuccessful();
        $response->assertJsonFragment([
            'author_name' => 'John Doe',
            'rating' => 5,
            'content' => 'Excellent service!',
        ]);
    });

    it('returns 404 for review in different tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherReview = Review::factory()->create(['location_id' => $otherLocation->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$otherReview->id}");

        $response->assertNotFound();
    });

    it('includes location data with review', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Downtown Store',
        ]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('data.location.name', 'Downtown Store');
    });

    it('includes response data if review has been responded to', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        $reviewResponse = ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'content' => 'Thank you for your feedback!',
            'status' => 'published',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('data.response.content', 'Thank you for your feedback!');
    });
});

describe('Review Response', function () {
    it('allows admins to create a response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Thank you for your review!',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['content' => 'Thank you for your review!']);
    });

    it('allows owners to create a response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'owner'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'We appreciate your feedback!',
        ]);

        $response->assertCreated();
    });

    it('denies members from creating responses', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Thank you!',
        ]);

        $response->assertForbidden();
    });

    it('requires content for response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['content']);
    });

    it('creates response as draft by default', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Thank you!',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
    });

    it('allows updating a draft response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        $reviewResponse = ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Updated response content',
        ]);

        $response->assertSuccessful();
        $response->assertJsonFragment(['content' => 'Updated response content']);
    });

    it('prevents updating a published response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        $reviewResponse = ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'status' => 'published',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Trying to update published response',
        ]);

        $response->assertForbidden();
    });

    it('allows publishing a draft response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        $reviewResponse = ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response/publish");

        $response->assertSuccessful();
        $response->assertJsonPath('data.status', 'published');
    });

    it('prevents creating duplicate responses', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        ReviewResponse::factory()->create(['review_id' => $review->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Duplicate response',
        ]);

        $response->assertUnprocessable();
    });

    it('allows deleting a draft response', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);
        $reviewResponse = ReviewResponse::factory()->create([
            'review_id' => $review->id,
            'user_id' => $user->id,
            'status' => 'draft',
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response");

        $response->assertNoContent();
        $this->assertDatabaseMissing('review_responses', ['id' => $reviewResponse->id]);
    });
});

describe('Review Statistics', function () {
    it('returns review statistics for tenant', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(3)->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->count(2)->create(['location_id' => $location->id, 'rating' => 4]);
        Review::factory()->count(1)->create(['location_id' => $location->id, 'rating' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/stats");

        $response->assertSuccessful();
        $response->assertJsonPath('data.total_reviews', 6);
        expect((float) $response->json('data.average_rating'))->toBe(4.0);
    });

    it('returns review statistics by platform', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(3)->create([
            'location_id' => $location->id,
            'platform' => 'google',
            'rating' => 5,
        ]);
        Review::factory()->count(2)->create([
            'location_id' => $location->id,
            'platform' => 'yelp',
            'rating' => 3,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/stats");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'total_reviews',
                'average_rating',
                'by_platform' => [
                    'google' => ['count', 'average_rating'],
                    'yelp' => ['count', 'average_rating'],
                ],
            ],
        ]);
    });

    it('returns rating distribution', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(5)->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->count(3)->create(['location_id' => $location->id, 'rating' => 4]);
        Review::factory()->count(1)->create(['location_id' => $location->id, 'rating' => 3]);
        Review::factory()->count(1)->create(['location_id' => $location->id, 'rating' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/stats");

        $response->assertSuccessful();
        $data = $response->json('data');
        expect($data)->toHaveKey('rating_distribution');
        expect(array_values($data['rating_distribution']))->toBe([5, 3, 1, 0, 1]);
    });

    it('returns statistics for specific location', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location1 = Location::factory()->create(['tenant_id' => $tenant->id]);
        $location2 = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->count(5)->create(['location_id' => $location1->id, 'rating' => 5]);
        Review::factory()->count(3)->create(['location_id' => $location2->id, 'rating' => 3]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/locations/{$location1->id}/reviews/stats");

        $response->assertSuccessful();
        $response->assertJsonPath('data.total_reviews', 5);
        expect((float) $response->json('data.average_rating'))->toBe(5.0);
    });
});

describe('Review Sync', function () {
    it('allows admins to trigger review sync for a location', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/reviews/sync");

        $response->assertAccepted();
    });

    it('requires google_place_id to sync reviews', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'google_place_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/reviews/sync");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['google_place_id']);
    });

    it('denies members from triggering review sync', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/reviews/sync");

        $response->assertForbidden();
    });

    it('tracks last sync time', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/tenants/{$tenant->id}/locations/{$location->id}/reviews/sync");

        $location->refresh();
        expect($location->reviews_synced_at)->not->toBeNull();
    });
});

describe('Review Sorting', function () {
    it('sorts reviews by date descending by default', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        $oldReview = Review::factory()->create([
            'location_id' => $location->id,
            'published_at' => now()->subDays(10),
        ]);
        $newReview = Review::factory()->create([
            'location_id' => $location->id,
            'published_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertSuccessful();
        $data = $response->json('data');
        expect($data[0]['id'])->toBe($newReview->id);
        expect($data[1]['id'])->toBe($oldReview->id);
    });

    it('sorts reviews by rating', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);

        Review::factory()->create(['location_id' => $location->id, 'rating' => 3]);
        Review::factory()->create(['location_id' => $location->id, 'rating' => 5]);
        Review::factory()->create(['location_id' => $location->id, 'rating' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews?sort=rating&direction=desc");

        $response->assertSuccessful();
        $data = $response->json('data');
        expect($data[0]['rating'])->toBe(5);
        expect($data[1]['rating'])->toBe(3);
        expect($data[2]['rating'])->toBe(1);
    });
});

describe('Review Edge Cases', function () {
    it('handles reviews with empty content', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create([
            'location_id' => $location->id,
            'content' => null,
            'rating' => 5,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('data.content', null);
    });

    it('handles reviews with special characters in content', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create([
            'location_id' => $location->id,
            'content' => 'Great food! 🍕 Best in town! <script>alert("xss")</script>',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}");

        $response->assertSuccessful();
    });

    it('handles deleted location gracefully', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        $reviewId = $review->id;
        $location->delete();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews/{$reviewId}");

        $response->assertNotFound();
    });

    it('returns empty list when no reviews exist', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'member'])->create();
        Location::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/reviews");

        $response->assertSuccessful();
        $response->assertJsonCount(0, 'data');
    });

    it('handles concurrent response creation', function () {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->hasAttached($user, ['role' => 'admin'])->create();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $review = Review::factory()->create(['location_id' => $location->id]);

        Sanctum::actingAs($user);

        // First response should succeed
        $response1 = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'First response',
        ]);
        $response1->assertCreated();

        // Second response should fail
        $response2 = $this->postJson("/api/v1/tenants/{$tenant->id}/reviews/{$review->id}/response", [
            'content' => 'Second response',
        ]);
        $response2->assertUnprocessable();
    });
});
