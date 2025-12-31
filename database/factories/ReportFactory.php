<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'location_id' => null,
            'report_template_id' => null,
            'report_schedule_id' => null,
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(['reviews', 'sentiment', 'summary', 'trends', 'location_comparison', 'reviews_detailed']),
            'format' => fake()->randomElement(['pdf', 'excel', 'csv']),
            'status' => 'pending',
            'progress' => 0,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'date_from' => now()->subDays(30),
            'date_to' => now(),
            'period' => 'last_30_days',
            'location_ids' => null,
            'filters' => null,
            'branding' => null,
            'options' => null,
            'error_message' => null,
            'completed_at' => null,
            'expires_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'progress' => 0,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'progress' => fake()->numberBetween(1, 99),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'progress' => 100,
            'file_path' => 'reports/'.fake()->uuid().'.pdf',
            'file_name' => fake()->slug().'.pdf',
            'file_size' => fake()->numberBetween(10000, 5000000),
            'completed_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }

    public function expired(): static
    {
        return $this->completed()->state(fn (array $attributes) => [
            'completed_at' => now()->subDays(45),
            'expires_at' => now()->subDays(15),
        ]);
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => 'pdf',
        ]);
    }

    public function excel(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => 'excel',
        ]);
    }

    public function csv(): static
    {
        return $this->state(fn (array $attributes) => [
            'format' => 'csv',
        ]);
    }

    public function reviews(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'reviews',
        ]);
    }

    public function sentiment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sentiment',
        ]);
    }

    public function summary(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'summary',
        ]);
    }

    public function trends(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'trends',
        ]);
    }

    public function locationComparison(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'location_comparison',
        ]);
    }

    public function reviewsDetailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'reviews_detailed',
        ]);
    }

    public function withLocation(?Location $location = null): static
    {
        return $this->state(fn (array $attributes) => [
            'location_id' => $location?->id ?? Location::factory(),
        ]);
    }

    public function withMultipleLocations(array $locationIds): static
    {
        return $this->state(fn (array $attributes) => [
            'location_ids' => $locationIds,
        ]);
    }

    public function withTemplate(?ReportTemplate $template = null): static
    {
        return $this->state(fn (array $attributes) => [
            'report_template_id' => $template?->id ?? ReportTemplate::factory(),
        ]);
    }

    public function withSchedule(?ReportSchedule $schedule = null): static
    {
        return $this->state(fn (array $attributes) => [
            'report_schedule_id' => $schedule?->id ?? ReportSchedule::factory(),
        ]);
    }

    public function withBranding(?array $branding = null): static
    {
        return $this->state(fn (array $attributes) => [
            'branding' => $branding ?? [
                'logo_url' => fake()->imageUrl(200, 50),
                'primary_color' => fake()->hexColor(),
                'secondary_color' => fake()->hexColor(),
                'company_name' => fake()->company(),
                'footer_text' => fake()->sentence(),
            ],
        ]);
    }

    public function withFilters(?array $filters = null): static
    {
        return $this->state(fn (array $attributes) => [
            'filters' => $filters ?? [
                'min_rating' => fake()->numberBetween(1, 3),
                'max_rating' => fake()->numberBetween(4, 5),
                'sources' => ['google', 'yelp'],
            ],
        ]);
    }

    public function withDateRange(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'date_from' => $from,
            'date_to' => $to,
            'period' => null,
        ]);
    }

    public function withPeriod(string $period): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => $period,
            'date_from' => null,
            'date_to' => null,
        ]);
    }
}
