<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'platform' => fake()->randomElement(['google', 'yelp', 'facebook']),
            'external_id' => fake()->uuid(),
            'author_name' => fake()->name(),
            'author_image' => fake()->optional()->imageUrl(100, 100, 'people'),
            'rating' => fake()->numberBetween(1, 5),
            'content' => fake()->optional(0.9)->paragraph(),
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'metadata' => null,
        ];
    }

    public function google(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => 'google',
        ]);
    }

    public function yelp(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => 'yelp',
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => 'facebook',
        ]);
    }

    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(4, 5),
        ]);
    }

    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => fake()->numberBetween(1, 2),
        ]);
    }

    public function withRating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
        ]);
    }
}
