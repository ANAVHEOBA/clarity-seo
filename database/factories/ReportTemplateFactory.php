<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(3, true).' Template',
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['reviews', 'sentiment', 'summary', 'trends', 'location_comparison', 'reviews_detailed']),
            'format' => 'pdf',
            'sections' => null,
            'branding' => null,
            'filters' => null,
            'options' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
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

    public function withSections(?array $sections = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sections' => $sections ?? [
                'summary' => true,
                'charts' => true,
                'reviews_list' => true,
                'sentiment_analysis' => true,
                'recommendations' => false,
            ],
        ]);
    }

    public function withFilters(?array $filters = null): static
    {
        return $this->state(fn (array $attributes) => [
            'filters' => $filters ?? [
                'min_rating' => 1,
                'max_rating' => 5,
                'sources' => ['google', 'yelp', 'facebook'],
            ],
        ]);
    }
}
