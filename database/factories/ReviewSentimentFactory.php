<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewSentiment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewSentiment>
 */
class ReviewSentimentFactory extends Factory
{
    protected $model = ReviewSentiment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sentiment = $this->faker->randomElement(['positive', 'negative', 'neutral', 'mixed']);

        return [
            'review_id' => Review::factory(),
            'sentiment' => $sentiment,
            'sentiment_score' => match ($sentiment) {
                'positive' => $this->faker->randomFloat(2, 0.7, 1.0),
                'negative' => $this->faker->randomFloat(2, 0.0, 0.3),
                'neutral' => $this->faker->randomFloat(2, 0.4, 0.6),
                'mixed' => $this->faker->randomFloat(2, 0.35, 0.65),
            },
            'emotions' => $this->generateEmotions($sentiment),
            'topics' => $this->generateTopics(),
            'keywords' => $this->generateKeywords(),
            'analyzed_at' => now(),
        ];
    }

    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'sentiment' => 'positive',
            'sentiment_score' => $this->faker->randomFloat(2, 0.7, 1.0),
            'emotions' => $this->generateEmotions('positive'),
        ]);
    }

    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'sentiment' => 'negative',
            'sentiment_score' => $this->faker->randomFloat(2, 0.0, 0.3),
            'emotions' => $this->generateEmotions('negative'),
        ]);
    }

    public function neutral(): static
    {
        return $this->state(fn (array $attributes) => [
            'sentiment' => 'neutral',
            'sentiment_score' => $this->faker->randomFloat(2, 0.4, 0.6),
            'emotions' => [],
        ]);
    }

    public function mixed(): static
    {
        return $this->state(fn (array $attributes) => [
            'sentiment' => 'mixed',
            'sentiment_score' => $this->faker->randomFloat(2, 0.35, 0.65),
            'emotions' => $this->generateEmotions('mixed'),
        ]);
    }

    /** @return array<string, float> */
    protected function generateEmotions(string $sentiment): array
    {
        $positiveEmotions = ['happy', 'satisfied', 'grateful', 'excited', 'relieved', 'impressed'];
        $negativeEmotions = ['angry', 'frustrated', 'disappointed', 'annoyed', 'upset', 'confused'];

        $emotions = [];
        $count = $this->faker->numberBetween(0, 3);

        if ($sentiment === 'positive') {
            $selected = $this->faker->randomElements($positiveEmotions, $count);
        } elseif ($sentiment === 'negative') {
            $selected = $this->faker->randomElements($negativeEmotions, $count);
        } elseif ($sentiment === 'mixed') {
            $selected = array_merge(
                $this->faker->randomElements($positiveEmotions, 1),
                $this->faker->randomElements($negativeEmotions, 1)
            );
        } else {
            return [];
        }

        foreach ($selected as $emotion) {
            $emotions[$emotion] = $this->faker->randomFloat(2, 0.5, 1.0);
        }

        return $emotions;
    }

    /** @return array<int, array{topic: string, sentiment: string, score: float}> */
    protected function generateTopics(): array
    {
        $availableTopics = [
            'service', 'staff', 'price', 'cleanliness', 'location',
            'wait_time', 'quality', 'atmosphere', 'food', 'parking',
        ];

        $count = $this->faker->numberBetween(0, 4);
        $selectedTopics = $this->faker->randomElements($availableTopics, $count);

        return array_map(fn ($topic) => [
            'topic' => $topic,
            'sentiment' => $this->faker->randomElement(['positive', 'negative', 'neutral']),
            'score' => $this->faker->randomFloat(2, 0.1, 1.0),
        ], $selectedTopics);
    }

    /** @return array<int, string> */
    protected function generateKeywords(): array
    {
        $positiveKeywords = ['friendly', 'helpful', 'quick', 'professional', 'clean', 'amazing', 'excellent', 'recommended', 'great', 'awesome'];
        $negativeKeywords = ['slow', 'rude', 'expensive', 'dirty', 'disappointing', 'terrible', 'avoid', 'worst', 'unprofessional'];
        $neutralKeywords = ['okay', 'average', 'decent', 'fine', 'normal'];

        $allKeywords = array_merge($positiveKeywords, $negativeKeywords, $neutralKeywords);
        $count = $this->faker->numberBetween(0, 5);

        return $this->faker->randomElements($allKeywords, $count);
    }
}
