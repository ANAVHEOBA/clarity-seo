<?php

namespace Tests\Feature\Schema;

use App\Helpers\SchemaHelper;
use App\Models\Location;
use App\Models\Review;
use App\Services\Schema\ReviewSchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemReviewSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_review_schema_with_platform_data(): void
    {
        $review = Review::factory()->create([
            'platform' => 'google',
            'author_name' => 'Jane Doe',
            'rating' => 5,
            'content' => 'Excellent service and staff!',
        ]);

        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals('Review', $schema['@type']);
        $this->assertEquals('Jane Doe', $schema['author']['name']);
        $this->assertEquals(5, $schema['reviewRating']['ratingValue']);
    }

    public function test_reviews_from_different_platforms(): void
    {
        $location = Location::factory()->create();

        $platforms = ['google', 'facebook', 'yelp', 'trustpilot'];

        foreach ($platforms as $platform) {
            Review::factory()->create([
                'location_id' => $location->id,
                'platform' => $platform,
                'rating' => rand(4, 5),
                'author_name' => "Reviewer from {$platform}",
            ]);
        }

        $location->load('reviews');
        $reviews = $location->reviews;

        $this->assertEquals(4, count($reviews));

        foreach ($reviews as $review) {
            $generator = new ReviewSchemaGenerator($review);
            $schema = $generator->generate();

            $this->assertEquals('Review', $schema['@type']);
            $this->assertNotEmpty($schema['author']['name']);
            $this->assertTrue(str_contains($schema['author']['name'], 'Reviewer'));
        }
    }

    public function test_item_review_uniqueness(): void
    {
        $location = Location::factory()->create();

        $review1 = Review::factory()->for($location)->create([
            'author_name' => 'User A',
            'content' => 'Great experience',
            'rating' => 5,
        ]);

        $review2 = Review::factory()->for($location)->create([
            'author_name' => 'User B',
            'content' => 'Different experience',
            'rating' => 4,
        ]);

        $generator1 = new ReviewSchemaGenerator($review1);
        $generator2 = new ReviewSchemaGenerator($review2);

        $schema1 = $generator1->generate();
        $schema2 = $generator2->generate();

        // Both should be valid Review schemas
        $this->assertEquals('Review', $schema1['@type']);
        $this->assertEquals('Review', $schema2['@type']);

        // But different content
        $this->assertNotEquals($schema1['reviewBody'], $schema2['reviewBody']);
        $this->assertNotEquals($schema1['author']['name'], $schema2['author']['name']);
    }

    public function test_item_review_with_high_rating(): void
    {
        $review = Review::factory()->create(['rating' => 5]);
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals(5, $schema['reviewRating']['ratingValue']);
    }

    public function test_item_review_with_low_rating(): void
    {
        $review = Review::factory()->create(['rating' => 1]);
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals(1, $schema['reviewRating']['ratingValue']);
    }

    public function test_item_review_rating_scale(): void
    {
        $review = Review::factory()->create(['rating' => 3]);
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals(1, $schema['reviewRating']['worstRating']);
        $this->assertEquals(5, $schema['reviewRating']['bestRating']);
    }

    public function test_multiple_item_reviews_in_widget(): void
    {
        $location = Location::factory()->create();
        $location->generateEmbedKey();

        $reviews = Review::factory(10)->for($location)->create([
            'rating' => 4,
        ]);

        $response = $this->get("/api/v1/embed/{$location->embed_key}/reviews");

        $response->assertStatus(200);

        $jsonLds = SchemaHelper::extractAllJsonLd($response->getContent());
        $itemReviews = collect($jsonLds)->filter(fn ($schema) => ($schema['@type'] ?? null) === 'Review');

        $this->assertEquals(10, count($itemReviews));

        // Verify each has required fields
        foreach ($itemReviews as $review) {
            $this->assertNotEmpty($review['author']);
            $this->assertNotEmpty($review['reviewRating']);
            $this->assertNotEmpty($review['datePublished']);
        }
    }

    public function test_item_review_with_empty_content(): void
    {
        $review = Review::factory()->create([
            'content' => null,
            'rating' => 4,
        ]);

        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        // Should still have name from first 100 chars of content (or "Review")
        $this->assertNotEmpty($schema['name']);
        $this->assertEquals('Review', $schema['name']);
    }

    public function test_item_review_content_truncation(): void
    {
        $longContent = str_repeat('This is a long review. ', 10);

        $review = Review::factory()->create([
            'content' => $longContent,
            'rating' => 5,
        ]);

        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        // Name should be substring of content
        $this->assertLessThanOrEqual(100, strlen($schema['name']));
        $this->assertEquals(substr($longContent, 0, 100), $schema['name']);
    }

    public function test_item_review_requires_author(): void
    {
        $review = Review::factory()->create(['author_name' => null]);
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertNotEmpty($schema['author']);
        $this->assertEquals('Anonymous', $schema['author']['name']);
    }

    public function test_item_review_published_date(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertNotEmpty($schema['datePublished']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $schema['datePublished']);
    }

    public function test_item_review_json_ld_format(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);

        $json = $generator->toJson();
        $decoded = json_decode($json, true);

        // Verify structure matches JSON-LD spec
        $this->assertEquals('https://schema.org', $decoded['@context']);
        $this->assertEquals('Review', $decoded['@type']);
        $this->assertIsArray($decoded['author']);
        $this->assertIsArray($decoded['reviewRating']);
        // reviewBody can be missing if content is null
        if (isset($decoded['reviewBody'])) {
            $this->assertTrue(is_string($decoded['reviewBody']) || is_null($decoded['reviewBody']));
        }
    }
}
