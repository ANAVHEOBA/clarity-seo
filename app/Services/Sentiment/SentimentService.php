<?php

declare(strict_types=1);

namespace App\Services\Sentiment;

use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SentimentService
{
    protected string $baseUrl;

    protected string $model;

    public function __construct()
    {
        $this->baseUrl = config('openrouter.base_url');
        $this->model = config('openrouter.model');
    }

    public function analyzeReview(Review $review): ?ReviewSentiment
    {
        $apiKey = config('openrouter.api_key');

        if (empty($apiKey)) {
            Log::warning('OpenRouter API key not configured');

            return null;
        }

        $content = $review->content ?? '';
        $rating = $review->rating;

        $prompt = $this->buildAnalysisPrompt($content, $rating);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a sentiment analysis expert. Analyze reviews and return JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.1,
                'max_tokens' => 1000,
            ]);

            if (! $response->successful()) {
                Log::error('OpenRouter API error', [
                    'review_id' => $review->id,
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);

                return null;
            }

            $responseContent = $response->json('choices.0.message.content');
            $analysis = $this->parseAnalysisResponse($responseContent);

            if (! $analysis) {
                Log::error('Failed to parse sentiment analysis response', [
                    'review_id' => $review->id,
                    'response' => $responseContent,
                ]);

                return null;
            }

            return ReviewSentiment::updateOrCreate(
                ['review_id' => $review->id],
                [
                    'sentiment' => $analysis['sentiment'],
                    'sentiment_score' => $analysis['sentiment_score'],
                    'emotions' => $analysis['emotions'] ?? [],
                    'topics' => $analysis['topics'] ?? [],
                    'keywords' => $analysis['keywords'] ?? [],
                    'language' => $analysis['language'] ?? null,
                    'analyzed_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Sentiment analysis exception', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @return array{analyzed_count: int, skipped_count: int, failed_count: int} */
    public function analyzeLocationReviews(Location $location, bool $force = false): array
    {
        $totalReviews = $location->reviews()->count();
        $alreadyAnalyzed = $location->reviews()->whereHas('sentiment')->count();

        $query = $location->reviews();

        if (! $force) {
            $query->whereDoesntHave('sentiment');
        }

        $reviews = $query->get();
        $analyzedCount = 0;
        $failedCount = 0;

        foreach ($reviews as $review) {
            $sentiment = $this->analyzeReview($review);

            if ($sentiment) {
                $analyzedCount++;
            } else {
                $failedCount++;
            }
        }

        // When force=true, skipped is 0. Otherwise, it's the pre-analyzed count.
        $skippedCount = $force ? 0 : $alreadyAnalyzed;

        return [
            'analyzed_count' => $analyzedCount,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
        ];
    }

    public function getReviewSentiment(Review $review): ?ReviewSentiment
    {
        return $review->sentiment;
    }

    /** @return array<string, mixed> */
    public function getAggregatedStats(Tenant $tenant, ?Location $location = null, array $filters = []): array
    {
        $query = $this->buildSentimentQuery($tenant, $location, $filters);

        $total = $query->count();

        if ($total === 0) {
            return [
                'total_analyzed' => 0,
                'sentiment_distribution' => [
                    'positive' => 0,
                    'negative' => 0,
                    'neutral' => 0,
                    'mixed' => 0,
                ],
                'average_sentiment_score' => 0,
                'sentiment_percentages' => [
                    'positive' => 0,
                    'negative' => 0,
                    'neutral' => 0,
                    'mixed' => 0,
                ],
            ];
        }

        $distribution = (clone $query)
            ->select('sentiment', DB::raw('count(*) as count'))
            ->groupBy('sentiment')
            ->pluck('count', 'sentiment')
            ->toArray();

        $avgScore = (clone $query)->avg('sentiment_score');

        return [
            'total_analyzed' => $total,
            'sentiment_distribution' => [
                'positive' => $distribution['positive'] ?? 0,
                'negative' => $distribution['negative'] ?? 0,
                'neutral' => $distribution['neutral'] ?? 0,
                'mixed' => $distribution['mixed'] ?? 0,
            ],
            'average_sentiment_score' => round((float) $avgScore, 3),
            'sentiment_percentages' => [
                'positive' => round(($distribution['positive'] ?? 0) / $total * 100, 1),
                'negative' => round(($distribution['negative'] ?? 0) / $total * 100, 1),
                'neutral' => round(($distribution['neutral'] ?? 0) / $total * 100, 1),
                'mixed' => round(($distribution['mixed'] ?? 0) / $total * 100, 1),
            ],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getTopics(Tenant $tenant, ?Location $location = null, array $filters = []): Collection
    {
        $query = $this->buildSentimentQuery($tenant, $location, $filters);

        $sentiments = $query->whereNotNull('topics')->get();

        $topicStats = [];

        foreach ($sentiments as $sentiment) {
            foreach ($sentiment->topics ?? [] as $topic) {
                $topicName = $topic['topic'];
                $topicSentiment = $topic['sentiment'] ?? 'neutral';

                if (! isset($topicStats[$topicName])) {
                    $topicStats[$topicName] = [
                        'topic' => $topicName,
                        'count' => 0,
                        'positive_count' => 0,
                        'negative_count' => 0,
                        'neutral_count' => 0,
                        'total_score' => 0,
                    ];
                }

                $topicStats[$topicName]['count']++;
                $topicStats[$topicName]["{$topicSentiment}_count"]++;
                $topicStats[$topicName]['total_score'] += $topic['score'] ?? 0;
            }
        }

        $result = collect($topicStats)->map(function ($stat) {
            $stat['average_score'] = $stat['count'] > 0
                ? round($stat['total_score'] / $stat['count'], 2)
                : 0;
            unset($stat['total_score']);

            return $stat;
        });

        $sortField = $filters['sort'] ?? 'count';
        $sortDirection = $filters['direction'] ?? 'desc';

        $result = $result->sortBy($sortField, SORT_REGULAR, $sortDirection === 'desc');

        if (isset($filters['limit'])) {
            $result = $result->take((int) $filters['limit']);
        }

        return $result->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getKeywords(Tenant $tenant, ?Location $location = null, array $filters = []): Collection
    {
        $query = $this->buildSentimentQuery($tenant, $location, $filters);

        $sentiments = $query->whereNotNull('keywords')->get();
        $totalReviews = $sentiments->count();

        $keywordCounts = [];

        foreach ($sentiments as $sentiment) {
            foreach ($sentiment->keywords ?? [] as $keyword) {
                $keyword = strtolower($keyword);
                $keywordCounts[$keyword] = ($keywordCounts[$keyword] ?? 0) + 1;
            }
        }

        $result = collect($keywordCounts)->map(fn ($count, $keyword) => [
            'keyword' => $keyword,
            'count' => $count,
            'percentage' => $totalReviews > 0 ? round($count / $totalReviews * 100, 1) : 0,
        ]);

        if (isset($filters['min_count'])) {
            $result = $result->filter(fn ($item) => $item['count'] >= (int) $filters['min_count']);
        }

        $sortField = $filters['sort'] ?? 'count';
        $sortDirection = $filters['direction'] ?? 'desc';

        $result = $result->sortBy($sortField, SORT_REGULAR, $sortDirection === 'desc');

        if (isset($filters['limit'])) {
            $result = $result->take((int) $filters['limit']);
        }

        return $result->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getEmotions(Tenant $tenant, ?Location $location = null, array $filters = []): Collection
    {
        $query = $this->buildSentimentQuery($tenant, $location, $filters);

        $sentiments = $query->whereNotNull('emotions')->get();
        $totalReviews = $sentiments->count();

        $positiveEmotions = ['happy', 'satisfied', 'grateful', 'excited', 'relieved', 'impressed'];
        $negativeEmotions = ['angry', 'frustrated', 'disappointed', 'annoyed', 'upset', 'confused'];

        $emotionStats = [];

        foreach ($sentiments as $sentiment) {
            foreach ($sentiment->emotions ?? [] as $emotion => $intensity) {
                if (! isset($emotionStats[$emotion])) {
                    $emotionStats[$emotion] = [
                        'emotion' => $emotion,
                        'count' => 0,
                        'total_intensity' => 0,
                    ];
                }

                $emotionStats[$emotion]['count']++;
                $emotionStats[$emotion]['total_intensity'] += $intensity;
            }
        }

        $result = collect($emotionStats)->map(function ($stat) use ($totalReviews) {
            $stat['average_intensity'] = $stat['count'] > 0
                ? round($stat['total_intensity'] / $stat['count'], 2)
                : 0;
            $stat['percentage'] = $totalReviews > 0
                ? round($stat['count'] / $totalReviews * 100, 1)
                : 0;
            unset($stat['total_intensity']);

            return $stat;
        });

        if (isset($filters['type'])) {
            $allowedEmotions = $filters['type'] === 'positive' ? $positiveEmotions : $negativeEmotions;
            $result = $result->filter(fn ($item) => in_array($item['emotion'], $allowedEmotions));
        }

        $result = $result->sortByDesc('count');

        if (isset($filters['limit'])) {
            $result = $result->take((int) $filters['limit']);
        }

        return $result->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getTrends(Tenant $tenant, ?Location $location = null, array $filters = []): Collection
    {
        $groupBy = $filters['group_by'] ?? 'day';

        $query = $this->buildSentimentQuery($tenant, $location, $filters);

        $sentiments = $query->with('review:id,published_at')->get();

        // Group in PHP for database compatibility (SQLite vs MySQL)
        $grouped = $sentiments->groupBy(function ($sentiment) use ($groupBy) {
            $date = $sentiment->review->published_at;

            return match ($groupBy) {
                'week' => $date->format('Y-W'),
                'month' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };
        });

        return $grouped->map(function ($items, $date) {
            return [
                'date' => $date,
                'total' => $items->count(),
                'positive' => $items->where('sentiment', 'positive')->count(),
                'negative' => $items->where('sentiment', 'negative')->count(),
                'neutral' => $items->where('sentiment', 'neutral')->count(),
                'mixed' => $items->where('sentiment', 'mixed')->count(),
                'average_score' => round($items->avg('sentiment_score'), 3),
            ];
        })->sortKeys()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function compareLocations(Tenant $tenant, array $locationIds): Collection
    {
        $locations = Location::whereIn('id', $locationIds)
            ->where('tenant_id', $tenant->id)
            ->get();

        return $locations->map(function ($location) use ($tenant) {
            $stats = $this->getAggregatedStats($tenant, $location);

            return [
                'location_id' => $location->id,
                'location_name' => $location->name,
                'total_analyzed' => $stats['total_analyzed'],
                'sentiment_distribution' => $stats['sentiment_distribution'],
                'average_score' => $stats['average_sentiment_score'],
            ];
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function exportSentimentData(Tenant $tenant, array $filters = []): Collection
    {
        $query = $this->buildSentimentQuery($tenant, null, $filters);

        if (isset($filters['location_id'])) {
            $query->whereHas('review', fn ($q) => $q->where('location_id', $filters['location_id']));
        }

        return $query->with('review:id,content,rating,published_at')->get()->map(fn ($sentiment) => [
            'review_id' => $sentiment->review_id,
            'sentiment' => $sentiment->sentiment,
            'sentiment_score' => $sentiment->sentiment_score,
            'emotions' => $sentiment->emotions,
            'topics' => $sentiment->topics,
            'keywords' => $sentiment->keywords,
            'analyzed_at' => $sentiment->analyzed_at?->toIso8601String(),
        ]);
    }

    /** @return Builder<ReviewSentiment> */
    protected function buildSentimentQuery(Tenant $tenant, ?Location $location = null, array $filters = []): Builder
    {
        $locationIds = $location
            ? [$location->id]
            : $tenant->locations()->pluck('id')->toArray();

        $query = ReviewSentiment::query()
            ->whereHas('review', fn ($q) => $q->whereIn('location_id', $locationIds));

        if (isset($filters['from'])) {
            $query->whereHas('review', fn ($q) => $q->whereDate('published_at', '>=', $filters['from']));
        }

        if (isset($filters['to'])) {
            $query->whereHas('review', fn ($q) => $q->whereDate('published_at', '<=', $filters['to']));
        }

        if (isset($filters['platform'])) {
            $query->whereHas('review', fn ($q) => $q->where('platform', $filters['platform']));
        }

        return $query;
    }

    protected function buildAnalysisPrompt(string $content, int $rating): string
    {
        $contentText = empty($content) ? "(No text content, rating only: {$rating}/5 stars)" : $content;

        return <<<PROMPT
Analyze this customer review and return a JSON object with the following structure:
{
    "sentiment": "positive" | "negative" | "neutral" | "mixed",
    "sentiment_score": 0.0 to 1.0 (0 = very negative, 1 = very positive),
    "emotions": {"emotion_name": intensity_0_to_1, ...},
    "topics": [{"topic": "topic_name", "sentiment": "positive|negative|neutral", "score": 0.0_to_1.0}, ...],
    "keywords": ["keyword1", "keyword2", ...],
    "language": "detected_language_code"
}

Topics should be business-relevant like: service, staff, price, cleanliness, location, wait_time, quality, atmosphere, food, parking, etc.

Emotions can include: happy, satisfied, grateful, excited, relieved, impressed, angry, frustrated, disappointed, annoyed, upset, confused.

Review (Rating: {$rating}/5 stars):
{$contentText}

Return ONLY valid JSON, no other text.
PROMPT;
    }

    /** @return array<string, mixed>|null */
    protected function parseAnalysisResponse(string $response): ?array
    {
        // Try to extract JSON from the response
        $response = trim($response);

        // Remove markdown code blocks if present
        if (str_starts_with($response, '```')) {
            $response = preg_replace('/^```(?:json)?\n?/', '', $response);
            $response = preg_replace('/\n?```$/', '', $response);
        }

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            // Validate required fields
            if (! isset($data['sentiment']) || ! isset($data['sentiment_score'])) {
                return null;
            }

            // Normalize sentiment
            $validSentiments = ['positive', 'negative', 'neutral', 'mixed'];
            if (! in_array($data['sentiment'], $validSentiments)) {
                $data['sentiment'] = 'neutral';
            }

            // Clamp sentiment score
            $data['sentiment_score'] = max(0, min(1, (float) $data['sentiment_score']));

            return $data;
        } catch (\JsonException $e) {
            return null;
        }
    }
}
