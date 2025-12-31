<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'report_template_id' => null,
            'name' => fake()->words(3, true).' Schedule',
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['reviews', 'sentiment', 'summary', 'trends', 'location_comparison', 'reviews_detailed']),
            'format' => 'pdf',
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'day_of_week' => null,
            'day_of_month' => null,
            'time_of_day' => '09:00:00',
            'timezone' => 'UTC',
            'period' => 'last_30_days',
            'location_ids' => null,
            'filters' => null,
            'branding' => null,
            'options' => null,
            'recipients' => null,
            'is_active' => true,
            'last_run_at' => null,
            'next_run_at' => now()->addDay(),
        ];
    }

    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'daily',
            'day_of_week' => null,
            'day_of_month' => null,
        ]);
    }

    public function weekly(string $dayOfWeek = 'monday'): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'day_of_week' => $dayOfWeek,
            'day_of_month' => null,
        ]);
    }

    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'day_of_week' => null,
            'day_of_month' => $dayOfMonth,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
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

    public function withTemplate(?ReportTemplate $template = null): static
    {
        return $this->state(fn (array $attributes) => [
            'report_template_id' => $template?->id ?? ReportTemplate::factory(),
        ]);
    }

    public function withRecipients(?array $recipients = null): static
    {
        return $this->state(fn (array $attributes) => [
            'recipients' => $recipients ?? [
                fake()->email(),
                fake()->email(),
            ],
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

    public function withLocationIds(array $locationIds): static
    {
        return $this->state(fn (array $attributes) => [
            'location_ids' => $locationIds,
        ]);
    }

    public function atTime(string $time, string $timezone = 'UTC'): static
    {
        return $this->state(fn (array $attributes) => [
            'time_of_day' => $time,
            'timezone' => $timezone,
        ]);
    }

    public function withPeriod(string $period): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => $period,
        ]);
    }
}
