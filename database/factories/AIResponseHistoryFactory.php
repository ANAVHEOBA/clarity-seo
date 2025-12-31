<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AIResponseHistory;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AIResponseHistory> */
class AIResponseHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'user_id' => User::factory(),
            'content' => fake()->paragraph(),
            'tone' => fake()->randomElement(['professional', 'friendly', 'apologetic', 'empathetic']),
            'language' => 'en',
            'brand_voice_id' => null,
            'metadata' => null,
        ];
    }

    public function withBrandVoice(int $brandVoiceId): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_voice_id' => $brandVoiceId,
        ]);
    }
}
