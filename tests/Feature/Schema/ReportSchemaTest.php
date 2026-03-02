<?php

namespace Tests\Feature\Schema;

use App\Helpers\SchemaHelper;
use App\Models\Report;
use App\Services\Schema\ReportSchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_schema_generator_creates_valid_schema(): void
    {
        $report = Report::factory()->create([
            'name' => 'Monthly Review Trends',
            'type' => 'trends',
        ]);

        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertEquals('https://schema.org', $schema['@context']);
        $this->assertEquals('Report', $schema['@type']);
        $this->assertNotEmpty($schema['name']);
        $this->assertNotEmpty($schema['author']);
    }

    public function test_report_schema_passes_validation(): void
    {
        $report = Report::factory()->create();
        $generator = new ReportSchemaGenerator($report);

        $this->assertTrue($generator->validate());
    }

    public function test_report_schema_to_json_is_valid(): void
    {
        $report = Report::factory()->create();
        $generator = new ReportSchemaGenerator($report);

        $json = $generator->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('Report', $decoded['@type']);
    }

    public function test_report_schema_with_data_metrics(): void
    {
        $report = Report::factory()->create([
            'name' => 'Sentiment Analysis Report',
            'type' => 'sentiment',
        ]);

        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertNotEmpty($schema['name']);
        $this->assertIsArray($schema['author']);
        $this->assertEquals('Organization', $schema['author']['@type']);
    }

    public function test_report_schema_has_author(): void
    {
        $report = Report::factory()->create();
        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertIsArray($schema['author']);
        $this->assertEquals('Organization', $schema['author']['@type']);
        $this->assertNotEmpty($schema['author']['name']);
        $this->assertNotEmpty($schema['author']['url']);
    }

    public function test_report_schema_date_published_is_iso8601(): void
    {
        $report = Report::factory()->create();
        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $schema['datePublished']
        );
    }

    public function test_report_schema_types(): void
    {
        $types = ['summary', 'trends', 'reviews', 'location-comparison', 'sentiment'];

        foreach ($types as $type) {
            $report = Report::factory()->create(['type' => $type]);
            $generator = new ReportSchemaGenerator($report);
            $schema = $generator->generate();

            $this->assertEquals('Report', $schema['@type']);
            $this->assertNotEmpty($schema['name']);
        }
    }

    public function test_report_schema_with_name(): void
    {
        $report = Report::factory()->create(['name' => 'Custom Report Name']);
        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertEquals('Custom Report Name', $schema['name']);
    }

    public function test_report_schema_fallback_to_type_if_no_name(): void
    {
        $report = Report::factory()->create([
            'name' => null,
            'type' => 'sentiment',
        ]);

        $generator = new ReportSchemaGenerator($report);
        $schema = $generator->generate();

        $this->assertEquals('sentiment', $schema['name']);
    }

    public function test_report_schema_multiple_reports_different_schemas(): void
    {
        $report1 = Report::factory()->create(['name' => 'Report 1', 'type' => 'summary']);
        $report2 = Report::factory()->create(['name' => 'Report 2', 'type' => 'trends']);

        $generator1 = new ReportSchemaGenerator($report1);
        $generator2 = new ReportSchemaGenerator($report2);

        $schema1 = $generator1->generate();
        $schema2 = $generator2->generate();

        $this->assertEquals('Report 1', $schema1['name']);
        $this->assertEquals('Report 2', $schema2['name']);
        // Both should be valid Report schemas
        $this->assertEquals('Report', $schema1['@type']);
        $this->assertEquals('Report', $schema2['@type']);
    }

    public function test_report_schema_serialization(): void
    {
        $report = Report::factory()->create();
        $generator = new ReportSchemaGenerator($report);

        // Test that schema can be used with SchemaHelper
        $json = $generator->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('Report', $decoded['@type']);
        $this->assertEquals('https://schema.org', $decoded['@context']);
    }
}
