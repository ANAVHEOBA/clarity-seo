<?php

namespace Tests\Feature\Schema;

use App\Helpers\SchemaHelper;
use App\Models\Location;
use App\Models\Review;
use App\Services\Schema\LocalBusinessSchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalBusinessSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_business_schema_generator_creates_valid_schema(): void
    {
        $location = Location::factory()->create([
            'name' => 'Test Business',
            'address' => '123 Main St',
            'city' => 'San Francisco',
            'state' => 'CA',
            'postal_code' => '94102',
            'country' => 'US',
            'phone' => '(415) 555-1234',
            'website' => 'https://example.com',
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertEquals('https://schema.org', $schema['@context']);
        $this->assertEquals('LocalBusiness', $schema['@type']);
        $this->assertEquals('Test Business', $schema['name']);
        $this->assertIsArray($schema['address']);
        $this->assertEquals('PostalAddress', $schema['address']['@type']);
    }

    public function test_local_business_schema_passes_validation(): void
    {
        $location = Location::factory()->create();
        $generator = new LocalBusinessSchemaGenerator($location);

        $this->assertTrue($generator->validate());
    }

    public function test_local_business_schema_to_json_is_valid(): void
    {
        $location = Location::factory()->create();
        $generator = new LocalBusinessSchemaGenerator($location);

        $json = $generator->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('LocalBusiness', $decoded['@type']);
    }

    public function test_local_business_with_aggregate_rating(): void
    {
        $location = Location::factory()->create();

        Review::factory(5)->for($location)->create([
            'rating' => 5,
        ]);

        Review::factory(3)->for($location)->create([
            'rating' => 4,
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertIsArray($schema['aggregateRating']);
        $this->assertEquals('AggregateRating', $schema['aggregateRating']['@type']);
        $this->assertEquals(8, $schema['aggregateRating']['reviewCount']);
        $this->assertGreaterThanOrEqual(4, $schema['aggregateRating']['ratingValue']);
    }

    public function test_local_business_without_reviews_no_aggregate_rating(): void
    {
        $location = Location::factory()->create();

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }

    public function test_local_business_with_partial_address(): void
    {
        $location = Location::factory()->create([
            'address' => '456 Oak Ave',
            'city' => 'Los Angeles',
            'state' => 'CA',
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertIsArray($schema['address']);
        $this->assertEquals('456 Oak Ave', $schema['address']['streetAddress']);
        $this->assertEquals('Los Angeles', $schema['address']['addressLocality']);
    }

    public function test_local_business_postal_address_format(): void
    {
        $location = Location::factory()->create([
            'address' => '789 Elm St',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
            'country' => 'US',
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $address = $schema['address'];
        $this->assertEquals('789 Elm St', $address['streetAddress']);
        $this->assertEquals('New York', $address['addressLocality']);
        $this->assertEquals('NY', $address['addressRegion']);
        $this->assertEquals('10001', $address['postalCode']);
        $this->assertEquals('US', $address['addressCountry']);
    }

    public function test_local_business_with_geo_coordinates(): void
    {
        $location = Location::factory()->create([
            'latitude' => 37.7749,
            'longitude' => -122.4194,
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertIsArray($schema['geo']);
        $this->assertEquals('GeoCoordinates', $schema['geo']['@type']);
        $this->assertEquals(37.7749, $schema['geo']['latitude']);
        $this->assertEquals(-122.4194, $schema['geo']['longitude']);
    }

    public function test_local_business_without_coordinates(): void
    {
        $location = Location::factory()->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertArrayNotHasKey('geo', $schema);
    }

    public function test_local_business_contact_info(): void
    {
        $location = Location::factory()->create([
            'phone' => '(415) 555-1234',
            'website' => 'https://example.com',
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertEquals('(415) 555-1234', $schema['telephone']);
        $this->assertEquals('https://example.com', $schema['url']);
    }

    public function test_local_business_same_as_urls(): void
    {
        $location = Location::factory()->create([
            'website' => 'https://example.com',
        ]);

        $generator = new LocalBusinessSchemaGenerator($location);
        $schema = $generator->generate();

        $this->assertIsArray($schema['sameAs']);
        $this->assertContains('https://example.com', $schema['sameAs']);
    }

    public function test_embed_widget_contains_local_business_schema(): void
    {
        $location = Location::factory()->create();
        $location->generateEmbedKey();
        Review::factory(2)->for($location)->create();

        $response = $this->get("/api/v1/embed/{$location->embed_key}/reviews");

        $response->assertStatus(200);

        $jsonLds = SchemaHelper::extractAllJsonLd($response->getContent());
        $localBusiness = collect($jsonLds)->first(fn ($schema) => ($schema['@type'] ?? null) === 'LocalBusiness');

        $this->assertNotNull($localBusiness);
        $this->assertEquals($location->name, $localBusiness['name']);
        $this->assertEquals('PostalAddress', $localBusiness['address']['@type']);
    }

    public function test_multiple_locations_each_have_proper_schema(): void
    {
        $location1 = Location::factory()->create(['name' => 'Business 1']);
        $location1->generateEmbedKey();
        $location2 = Location::factory()->create(['name' => 'Business 2']);
        $location2->generateEmbedKey();

        Review::factory(2)->for($location1)->create();
        Review::factory(2)->for($location2)->create();

        $response1 = $this->get("/api/v1/embed/{$location1->embed_key}/reviews");
        $response2 = $this->get("/api/v1/embed/{$location2->embed_key}/reviews");

        $schemas1 = SchemaHelper::extractAllJsonLd($response1->getContent());
        $schemas2 = SchemaHelper::extractAllJsonLd($response2->getContent());

        $business1 = collect($schemas1)->first(fn ($s) => ($s['@type'] ?? null) === 'LocalBusiness');
        $business2 = collect($schemas2)->first(fn ($s) => ($s['@type'] ?? null) === 'LocalBusiness');

        $this->assertEquals('Business 1', $business1['name']);
        $this->assertEquals('Business 2', $business2['name']);
    }
}
