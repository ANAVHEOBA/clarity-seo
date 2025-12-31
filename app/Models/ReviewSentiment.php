<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSentiment extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id',
        'sentiment',
        'sentiment_score',
        'emotions',
        'topics',
        'keywords',
        'language',
        'analyzed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sentiment_score' => 'float',
            'emotions' => 'array',
            'topics' => 'array',
            'keywords' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function isPositive(): bool
    {
        return $this->sentiment === 'positive';
    }

    public function isNegative(): bool
    {
        return $this->sentiment === 'negative';
    }

    public function isNeutral(): bool
    {
        return $this->sentiment === 'neutral';
    }

    public function isMixed(): bool
    {
        return $this->sentiment === 'mixed';
    }

    /** @return array<string, float> */
    public function getTopEmotions(int $limit = 3): array
    {
        if (empty($this->emotions)) {
            return [];
        }

        $emotions = $this->emotions;
        arsort($emotions);

        return array_slice($emotions, 0, $limit, true);
    }

    /** @return array<int, array{topic: string, sentiment: string, score: float}> */
    public function getTopTopics(int $limit = 5): array
    {
        if (empty($this->topics)) {
            return [];
        }

        $topics = $this->topics;
        usort($topics, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($topics, 0, $limit);
    }
}
