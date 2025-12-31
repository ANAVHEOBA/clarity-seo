<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewResponse> */
class ReviewResponseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'user_id' => User::factory(),
            'content' => fake()->paragraph(),
            'status' => 'draft',
            'ai_generated' => false,
            'brand_voice_id' => null,
            'tone' => 'professional',
            'language' => 'en',
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
            'published_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
            'approved_by' => User::factory(),
            'approved_at' => fake()->dateTimeBetween('-1 month', '-1 day'),
            'published_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);
    }

    public function aiGenerated(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_generated' => true,
        ]);
    }

    public function withTone(string $tone): static
    {
        return $this->state(fn (array $attributes) => [
            'tone' => $tone,
        ]);
    }

    public function withLanguage(string $language): static
    {
        return $this->state(fn (array $attributes) => [
            'language' => $language,
        ]);
    }
}
