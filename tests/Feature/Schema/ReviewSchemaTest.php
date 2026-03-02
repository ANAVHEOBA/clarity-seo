<?php

namespace Tests\Feature\Schema;

use App\Helpers\SchemaHelper;
use App\Models\Location;
use App\Models\Review;
use App\Services\Schema\ReviewSchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_schema_generator_creates_valid_schema(): void
    {
        $review = Review::factory()->create([
            'author_name' => 'John Doe',
            'rating' => 5,
            'content' => 'Great service!',
        ]);

        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals('https://schema.org', $schema['@context']);
        $this->assertEquals('Review', $schema['@type']);
        $this->assertEquals('John Doe', $schema['author']['name']);
        $this->assertEquals(5, $schema['reviewRating']['ratingValue']);
        $this->assertEquals('Great service!', $schema['reviewBody']);
    }

    public function test_review_schema_passes_validation(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);

        $this->assertTrue($generator->validate());
    }

    public function test_review_schema_to_json_is_valid(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);

        $json = $generator->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('Review', $decoded['@type']);
    }

    public function test_review_widget_has_valid_schema_markup(): void
    {
        $location = Location::factory()->create();
        $location->generateEmbedKey();
        $reviews = Review::factory(3)->for($location)->create();

        $response = $this->get("/api/v1/embed/{$location->embed_key}/reviews");

        $response->assertStatus(200);

        // Extract JSON-LD from HTML
        $jsonLds = SchemaHelper::extractAllJsonLd($response->getContent());

        // Should have LocalBusiness + 3 Reviews
        $this->assertGreaterThanOrEqual(4, count($jsonLds));

        // Verify at least one is LocalBusiness
        $hasLocalBusiness = collect($jsonLds)->some(fn ($schema) => ($schema['@type'] ?? null) === 'LocalBusiness');
        $this->assertTrue($hasLocalBusiness);

        // Verify reviews are present
        $reviews = collect($jsonLds)->filter(fn ($schema) => ($schema['@type'] ?? null) === 'Review');
        $this->assertGreaterThanOrEqual(3, count($reviews));
    }

    public function test_review_schema_with_anonymous_author(): void
    {
        $review = Review::factory()->create([
            'author_name' => null,
            'rating' => 4,
            'content' => 'Good service',
        ]);

        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertEquals('Anonymous', $schema['author']['name']);
    }

    public function test_review_schema_rating_bounded_1_to_5(): void
    {
        $review = Review::factory()->create(['rating' => 5]);
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertGreaterThanOrEqual(1, $schema['reviewRating']['ratingValue']);
        $this->assertLessThanOrEqual(5, $schema['reviewRating']['ratingValue']);
    }

    public function test_review_schema_has_publisher(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        $this->assertIsArray($schema['publisher']);
        $this->assertEquals('Organization', $schema['publisher']['@type']);
        $this->assertNotEmpty($schema['publisher']['name']);
    }

    public function test_review_schema_date_published_is_iso8601(): void
    {
        $review = Review::factory()->create();
        $generator = new ReviewSchemaGenerator($review);
        $schema = $generator->generate();

        // Check ISO 8601 format: 2024-01-15T10:30:45.123456Z
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $schema['datePublished']
        );
    }

    public function test_multiple_reviews_in_embed_widget(): void
    {
        $location = Location::factory()->create();
        $location->generateEmbedKey();
        $reviews = Review::factory(5)->for($location)->create();

        $response = $this->get("/api/v1/embed/{$location->embed_key}/reviews");

        $response->assertStatus(200);

        $jsonLds = SchemaHelper::extractAllJsonLd($response->getContent());
        $reviewSchemas = collect($jsonLds)->filter(fn ($schema) => ($schema['@type'] ?? null) === 'Review');

        $this->assertEquals(5, count($reviewSchemas));
    }

    public function test_embed_widget_renders_html_not_json(): void
    {
        $location = Location::factory()->create();
        $location->generateEmbedKey();
        Review::factory()->for($location)->create();

        $response = $this->get("/api/v1/embed/{$location->embed_key}/reviews");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        // Verify it returns HTML with showcase structure (check raw content)
        $this->assertStringContainsString('showcase-container', $response->getContent());
    }
}
