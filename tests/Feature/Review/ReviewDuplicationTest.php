<?php

declare(strict_types=1);

namespace Tests\Feature\Review;

use App\Models\Location;
use App\Models\PlatformCredential;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Review\FacebookReviewService;
use App\Services\Review\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = Tenant::factory()->create();
        $this->user->update(['current_tenant_id' => $this->tenant->id]);
        $this->tenant->users()->attach($this->user->id, ['role' => 'owner']);
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_prevents_duplicate_google_reviews_with_same_external_id(): void
    {
        $externalId = 'locations/123/reviews/abc123';

        // Create first review
        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'external_id' => $externalId,
            'author_name' => 'John Doe',
            'rating' => 5,
            'content' => 'Great service!',
            'published_at' => now(),
        ]);

        // Attempt to create duplicate - should update instead
        Review::updateOrCreate(
            [
                'location_id' => $this->location->id,
                'platform' => 'google',
                'external_id' => $externalId,
            ],
            [
                'author_name' => 'John Doe',
                'rating' => 4, // Changed rating
                'content' => 'Updated review',
                'published_at' => now(),
            ]
        );

        // Should only have 1 review
        $this->assertEquals(1, Review::count());
        
        // Should have updated content
        $review = Review::first();
        $this->assertEquals('Updated review', $review->content);
        $this->assertEquals(4, $review->rating);
    }

    public function test_prevents_duplicate_facebook_reviews_with_same_external_id(): void
    {
        $externalId = 'fb_story_123456';

        // Create first review
        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'facebook',
            'external_id' => $externalId,
            'author_name' => 'Jane Smith',
            'rating' => 5,
            'content' => 'Love it!',
            'published_at' => now(),
        ]);

        // Attempt to create duplicate
        Review::updateOrCreate(
            [
                'location_id' => $this->location->id,
                'platform' => 'facebook',
                'external_id' => $externalId,
            ],
            [
                'author_name' => 'Jane Smith',
                'rating' => 5,
                'content' => 'Love it!',
                'published_at' => now(),
            ]
        );

        // Should only have 1 review
        $this->assertEquals(1, Review::count());
    }

    public function test_external_id_cannot_be_null(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'external_id' => null, // Should fail
            'author_name' => 'Test User',
            'rating' => 5,
            'published_at' => now(),
        ]);
    }

    public function test_different_platforms_can_have_same_external_id(): void
    {
        $externalId = 'review_123';

        // Google review
        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'external_id' => $externalId,
            'author_name' => 'User A',
            'rating' => 5,
            'published_at' => now(),
        ]);

        // Facebook review with same external_id (different platform)
        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'facebook',
            'external_id' => $externalId,
            'author_name' => 'User B',
            'rating' => 4,
            'published_at' => now(),
        ]);

        // Should have 2 reviews (different platforms)
        $this->assertEquals(2, Review::count());
    }

    public function test_different_locations_can_have_same_external_id(): void
    {
        $location2 = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        $externalId = 'review_123';

        // Review for location 1
        Review::create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'external_id' => $externalId,
            'author_name' => 'User A',
            'rating' => 5,
            'published_at' => now(),
        ]);

        // Review for location 2 with same external_id
        Review::create([
            'location_id' => $location2->id,
            'platform' => 'google',
            'external_id' => $externalId,
            'author_name' => 'User B',
            'rating' => 4,
            'published_at' => now(),
        ]);

        // Should have 2 reviews (different locations)
        $this->assertEquals(2, Review::count());
    }

    public function test_facebook_fallback_external_id_generation_is_unique(): void
    {
        $service = app(FacebookReviewService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateFallbackExternalId');
        $method->setAccessible(true);

        // Same author, different content
        $id1 = $method->invoke($service, 'John Doe', '2024-01-01T10:00:00', 5, 'Great service!');
        $id2 = $method->invoke($service, 'John Doe', '2024-01-01T10:00:00', 5, 'Terrible service!');

        $this->assertNotEquals($id1, $id2, 'Different content should generate different IDs');

        // Same author, different time
        $id3 = $method->invoke($service, 'John Doe', '2024-01-01T10:00:00', 5, 'Great service!');
        $id4 = $method->invoke($service, 'John Doe', '2024-01-01T11:00:00', 5, 'Great service!');

        $this->assertNotEquals($id3, $id4, 'Different time should generate different IDs');

        // Same author, different rating
        $id5 = $method->invoke($service, 'John Doe', '2024-01-01T10:00:00', 5, 'Great service!');
        $id6 = $method->invoke($service, 'John Doe', '2024-01-01T10:00:00', 1, 'Great service!');

        $this->assertNotEquals($id5, $id6, 'Different rating should generate different IDs');
    }

    public function test_google_places_fallback_external_id_generation_is_unique(): void
    {
        $service = app(ReviewService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateGooglePlacesExternalId');
        $method->setAccessible(true);

        // Same author, different content
        $id1 = $method->invoke($service, 'John Doe', 1704096000, 5, 'Great service!');
        $id2 = $method->invoke($service, 'John Doe', 1704096000, 5, 'Terrible service!');

        $this->assertNotEquals($id1, $id2, 'Different content should generate different IDs');

        // Same author, different time
        $id3 = $method->invoke($service, 'John Doe', 1704096000, 5, 'Great service!');
        $id4 = $method->invoke($service, 'John Doe', 1704099600, 5, 'Great service!');

        $this->assertNotEquals($id3, $id4, 'Different time should generate different IDs');
    }
}
