<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Report;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'owner']);
    $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);

    Storage::fake('local');
});

/*
|--------------------------------------------------------------------------
| Report Generation - PDF
|--------------------------------------------------------------------------
*/

describe('PDF Report Generation', function () {
    it('generates a PDF report for reviews', function () {
        Review::factory()->count(10)->create([
            'location_id' => $this->location->id,
            'published_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'date_from' => now()->subMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'type',
                    'format',
                    'status',
                    'file_path',
                    'download_url',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.type', 'reviews')
            ->assertJsonPath('data.format', 'pdf');
    });

    it('generates a PDF report for sentiment analysis', function () {
        $reviews = Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
        ]);

        foreach ($reviews as $review) {
            ReviewSentiment::factory()->create(['review_id' => $review->id]);
        }

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'sentiment',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.type', 'sentiment');
    });

    it('generates a comprehensive summary report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.type', 'summary');
    });

    it('generates a location-specific report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'location_id' => $this->location->id,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.type', 'reviews');
    });

    it('includes charts in PDF report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
            'include_charts' => true,
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Report Generation - Excel
|--------------------------------------------------------------------------
*/

describe('Excel Report Generation', function () {
    it('generates an Excel report for reviews', function () {
        Review::factory()->count(10)->create([
            'location_id' => $this->location->id,
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'excel',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.format', 'excel');
    });

    it('generates an Excel report for sentiment data', function () {
        $reviews = Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
        ]);

        foreach ($reviews as $review) {
            ReviewSentiment::factory()->create(['review_id' => $review->id]);
        }

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'sentiment',
            'format' => 'excel',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.format', 'excel');
    });

    it('exports all reviews with full details in Excel', function () {
        Review::factory()->count(20)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews_detailed',
            'format' => 'excel',
        ]);

        $response->assertSuccessful();
    });

    it('generates multi-sheet Excel report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'excel',
            'multi_sheet' => true,
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Report Generation - CSV
|--------------------------------------------------------------------------
*/

describe('CSV Report Generation', function () {
    it('generates a CSV export of reviews', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'csv',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.format', 'csv');
    });

    it('includes headers in CSV export', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'csv',
            'include_headers' => true,
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Report Types
|--------------------------------------------------------------------------
*/

describe('Report Types', function () {
    it('generates reviews report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('generates sentiment report', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $review->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'sentiment',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('generates summary report with all metrics', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('generates comparison report for multiple locations', function () {
        $location2 = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);
        Review::factory()->count(5)->create(['location_id' => $location2->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'location_comparison',
            'format' => 'pdf',
            'location_ids' => [$this->location->id, $location2->id],
        ]);

        $response->assertSuccessful();
    });

    it('generates trends report', function () {
        Review::factory()->count(20)->create([
            'location_id' => $this->location->id,
            'published_at' => fn () => fake()->dateTimeBetween('-3 months', 'now'),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'trends',
            'format' => 'pdf',
            'date_from' => now()->subMonths(3)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $response->assertSuccessful();
    });

    it('validates report type', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'invalid_type',
            'format' => 'pdf',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    it('validates report format', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'invalid_format',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    });
});

/*
|--------------------------------------------------------------------------
| Date Range Filtering
|--------------------------------------------------------------------------
*/

describe('Date Range Filtering', function () {
    it('filters report by date range', function () {
        Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
            'published_at' => now()->subDays(5),
        ]);

        Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
            'published_at' => now()->subMonths(2),
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'date_from' => now()->subWeek()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $response->assertSuccessful();
    });

    it('generates last 7 days report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'period' => 'last_7_days',
        ]);

        $response->assertSuccessful();
    });

    it('generates last 30 days report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'period' => 'last_30_days',
        ]);

        $response->assertSuccessful();
    });

    it('generates last quarter report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
            'period' => 'last_quarter',
        ]);

        $response->assertSuccessful();
    });

    it('generates year to date report', function () {
        Review::factory()->count(10)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
            'period' => 'year_to_date',
        ]);

        $response->assertSuccessful();
    });

    it('validates date range', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'date_from' => now()->toDateString(),
            'date_to' => now()->subMonth()->toDateString(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    });
});

/*
|--------------------------------------------------------------------------
| Report Download
|--------------------------------------------------------------------------
*/

describe('Report Download', function () {
    it('downloads a generated report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $reportId = $createResponse->json('data.id');

        $response = $this->get("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}/download");

        $response->assertSuccessful();
    });

    it('returns 404 for non-existent report', function () {
        $response = $this->get("/api/v1/tenants/{$this->tenant->id}/reports/99999/download");

        $response->assertNotFound();
    });

    it('prevents downloading report from another tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        Review::factory()->count(5)->create(['location_id' => $otherLocation->id]);

        // Create report for other tenant (need to attach user first)
        $otherTenant->users()->attach($this->user, ['role' => 'owner']);

        $createResponse = $this->postJson("/api/v1/tenants/{$otherTenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $reportId = $createResponse->json('data.id');

        // Try to download from original tenant
        $response = $this->get("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}/download");

        $response->assertNotFound();
    });

    it('provides download URL with expiration', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['download_url'],
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Report List & History
|--------------------------------------------------------------------------
*/

describe('Report List & History', function () {
    it('lists all reports for tenant', function () {
        // Generate a few reports
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'sentiment',
            'format' => 'excel',
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'format', 'status', 'created_at'],
                ],
            ]);
    });

    it('filters reports by type', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports?type=reviews");

        $response->assertSuccessful();
    });

    it('filters reports by format', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports?format=pdf");

        $response->assertSuccessful();
    });

    it('shows a specific report details', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $reportId = $createResponse->json('data.id');

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $reportId);
    });

    it('deletes a report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $reportId = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}");

        $response->assertNoContent();

        $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}")
            ->assertNotFound();
    });

    it('paginates report list', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
                'type' => 'reviews',
                'format' => 'pdf',
            ]);
        }

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports?per_page=10");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'total'],
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Scheduled Reports
|--------------------------------------------------------------------------
*/

describe('Scheduled Reports', function () {
    it('creates a daily scheduled report', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Daily Reviews Report',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['manager@example.com'],
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'type',
                    'format',
                    'frequency',
                    'time',
                    'recipients',
                    'is_active',
                ],
            ])
            ->assertJsonPath('data.frequency', 'daily');
    });

    it('creates a weekly scheduled report', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Weekly Summary',
            'type' => 'summary',
            'format' => 'pdf',
            'frequency' => 'weekly',
            'day_of_week' => 'monday',
            'time' => '09:00',
            'recipients' => ['team@example.com'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.day_of_week', 'monday');
    });

    it('creates a monthly scheduled report', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Monthly Performance Report',
            'type' => 'summary',
            'format' => 'pdf',
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'time' => '08:00',
            'recipients' => ['executive@example.com'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.frequency', 'monthly')
            ->assertJsonPath('data.day_of_month', 1);
    });

    it('lists all scheduled reports', function () {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Schedule 1',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/report-schedules");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'frequency', 'is_active'],
                ],
            ]);
    });

    it('updates a scheduled report', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Original Name',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $scheduleId = $createResponse->json('data.id');

        $response = $this->putJson("/api/v1/tenants/{$this->tenant->id}/report-schedules/{$scheduleId}", [
            'name' => 'Updated Name',
            'time' => '10:00',
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.time', '10:00');
    });

    it('deletes a scheduled report', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'To Delete',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $scheduleId = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/v1/tenants/{$this->tenant->id}/report-schedules/{$scheduleId}");

        $response->assertNoContent();
    });

    it('toggles scheduled report active status', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Toggle Test',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $scheduleId = $createResponse->json('data.id');

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules/{$scheduleId}/toggle");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_active', false);

        // Toggle again
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules/{$scheduleId}/toggle");

        $response->assertSuccessful()
            ->assertJsonPath('data.is_active', true);
    });

    it('validates recipient emails', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Test',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'daily',
            'time' => '08:00',
            'recipients' => ['not-an-email', 'also-invalid'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['recipients.0', 'recipients.1']);
    });

    it('validates frequency', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Test',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'invalid',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['frequency']);
    });

    it('requires day_of_week for weekly schedule', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-schedules", [
            'name' => 'Weekly Test',
            'type' => 'reviews',
            'format' => 'pdf',
            'frequency' => 'weekly',
            'time' => '08:00',
            'recipients' => ['test@example.com'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['day_of_week']);
    });
});

/*
|--------------------------------------------------------------------------
| Report Email Delivery
|--------------------------------------------------------------------------
*/

describe('Report Email Delivery', function () {
    it('sends report via email', function () {
        Mail::fake();

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'send_email' => true,
            'recipients' => ['recipient@example.com'],
        ]);

        $response->assertSuccessful();

        Mail::assertQueued(\App\Mail\ReportReady::class);
    });

    it('sends report to multiple recipients', function () {
        Mail::fake();

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'send_email' => true,
            'recipients' => ['user1@example.com', 'user2@example.com', 'user3@example.com'],
        ]);

        $response->assertSuccessful();

        Mail::assertQueued(\App\Mail\ReportReady::class, 3);
    });

    it('includes custom message in email', function () {
        Mail::fake();

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'send_email' => true,
            'recipients' => ['recipient@example.com'],
            'email_subject' => 'Your Weekly Report',
            'email_message' => 'Please find attached your weekly review report.',
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| White Label / Branding
|--------------------------------------------------------------------------
*/

describe('Report Branding', function () {
    it('applies custom logo to report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'branding' => [
                'logo_url' => 'https://example.com/logo.png',
            ],
        ]);

        $response->assertSuccessful();
    });

    it('applies custom colors to report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'branding' => [
                'primary_color' => '#FF5733',
                'secondary_color' => '#3498DB',
            ],
        ]);

        $response->assertSuccessful();
    });

    it('applies custom company name to report', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'branding' => [
                'company_name' => 'Acme Corporation',
                'tagline' => 'Excellence in Service',
            ],
        ]);

        $response->assertSuccessful();
    });

    it('hides platform branding (white label)', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
            'branding' => [
                'white_label' => true,
            ],
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Report Templates
|--------------------------------------------------------------------------
*/

describe('Report Templates', function () {
    it('creates a custom report template', function () {
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'Executive Summary Template',
            'type' => 'summary',
            'sections' => ['overview', 'ratings', 'sentiment', 'trends'],
            'branding' => [
                'primary_color' => '#1a1a1a',
                'logo_url' => 'https://example.com/logo.png',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'type', 'sections', 'branding'],
            ]);
    });

    it('lists report templates', function () {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'Template 1',
            'type' => 'reviews',
            'sections' => ['overview'],
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/report-templates");

        $response->assertSuccessful();
    });

    it('generates report from template', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $templateResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'My Template',
            'type' => 'reviews',
            'sections' => ['overview', 'ratings'],
        ]);

        $templateId = $templateResponse->json('data.id');

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'template_id' => $templateId,
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('updates a report template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'Original',
            'type' => 'reviews',
            'sections' => ['overview'],
        ]);

        $templateId = $createResponse->json('data.id');

        $response = $this->putJson("/api/v1/tenants/{$this->tenant->id}/report-templates/{$templateId}", [
            'name' => 'Updated Template',
            'sections' => ['overview', 'ratings', 'sentiment'],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Template');
    });

    it('deletes a report template', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'To Delete',
            'type' => 'reviews',
            'sections' => ['overview'],
        ]);

        $templateId = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/v1/tenants/{$this->tenant->id}/report-templates/{$templateId}");

        $response->assertNoContent();
    });

    it('sets template as default', function () {
        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/report-templates", [
            'name' => 'Default Template',
            'type' => 'reviews',
            'sections' => ['overview'],
            'is_default' => true,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.is_default', true);
    });
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

describe('Report Authorization', function () {
    it('requires authentication', function () {
        $this->app['auth']->forgetGuards();

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertForbidden();
    });

    it('allows member to generate reports', function () {
        $member = User::factory()->create();
        $this->tenant->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member);

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

describe('Report Edge Cases', function () {
    it('handles empty data gracefully', function () {
        // No reviews created
        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('handles large dataset', function () {
        Review::factory()->count(100)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'excel',
        ]);

        $response->assertSuccessful();
    });

    it('handles special characters in data', function () {
        Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Great! "Best" service ever! <script>alert("xss")</script> 👍',
            'author_name' => 'José García',
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('handles multi-location report', function () {
        $locations = Location::factory()->count(5)->create(['tenant_id' => $this->tenant->id]);

        foreach ($locations as $location) {
            Review::factory()->count(5)->create(['location_id' => $location->id]);
        }

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'summary',
            'format' => 'pdf',
        ]);

        $response->assertSuccessful();
    });

    it('queues large report generation', function () {
        Review::factory()->count(500)->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews_detailed',
            'format' => 'excel',
        ]);

        $response->assertSuccessful();

        // In sync queue mode (tests), the job completes immediately
        // In production with async queue, status would be 'processing'
        $status = $response->json('data.status');
        expect(in_array($status, ['processing', 'completed']))->toBeTrue();
    });

    it('returns report generation status', function () {
        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $createResponse = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reports", [
            'type' => 'reviews',
            'format' => 'pdf',
        ]);

        $reportId = $createResponse->json('data.id');

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reports/{$reportId}/status");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => ['status', 'progress'],
            ]);
    });
});
