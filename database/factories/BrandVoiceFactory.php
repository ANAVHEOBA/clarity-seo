<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BrandVoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandVoice> */
class BrandVoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true).' Voice',
            'description' => fake()->sentence(),
            'tone' => fake()->randomElement(['professional', 'friendly', 'apologetic', 'empathetic']),
            'guidelines' => fake()->paragraph(),
            'example_responses' => [
                fake()->sentence(),
                fake()->sentence(),
            ],
            'is_default' => false,
        ];
    }

    public function professional(): static
    {
        return $this->state(fn (array $attributes) => [
            'tone' => 'professional',
            'name' => 'Professional Voice',
            'guidelines' => 'Use formal language. Be concise and respectful. Address concerns directly.',
        ]);
    }

    public function friendly(): static
    {
        return $this->state(fn (array $attributes) => [
            'tone' => 'friendly',
            'name' => 'Friendly Voice',
            'guidelines' => 'Be warm and approachable. Use casual but professional language.',
        ]);
    }

    public function apologetic(): static
    {
        return $this->state(fn (array $attributes) => [
            'tone' => 'apologetic',
            'name' => 'Apologetic Voice',
            'guidelines' => 'Express sincere apology. Take responsibility. Offer resolution.',
        ]);
    }

    public function empathetic(): static
    {
        return $this->state(fn (array $attributes) => [
            'tone' => 'empathetic',
            'name' => 'Empathetic Voice',
            'guidelines' => 'Show understanding. Acknowledge feelings. Be compassionate.',
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
