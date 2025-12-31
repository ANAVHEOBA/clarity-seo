<?php

declare(strict_types=1);

namespace App\Services\AIResponse;

use App\Models\AIResponseHistory;
use App\Models\BrandVoice;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewResponse;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIResponseService
{
    protected string $baseUrl;

    protected string $model;

    public const VALID_TONES = ['professional', 'friendly', 'apologetic', 'empathetic'];

    public const VALID_LANGUAGES = [
        'en', 'es', 'fr', 'de', 'it', 'pt', 'nl', 'ru', 'ja', 'ko',
        'zh', 'ar', 'hi', 'tr', 'pl', 'sv', 'da', 'no', 'fi', 'cs',
    ];

    public function __construct()
    {
        $this->baseUrl = config('openrouter.base_url');
        $this->model = config('openrouter.model');
    }

    /** @return array{response: ReviewResponse, quality_score?: float, quality_feedback?: string}|null */
    public function generateResponse(
        Review $review,
        User $user,
        array $options = []
    ): ?array {
        $apiKey = config('openrouter.api_key');

        if (empty($apiKey)) {
            throw new \RuntimeException('AI service not configured');
        }

        $tone = $options['tone'] ?? 'professional';
        $language = $options['language'] ?? 'en';
        $brandVoice = isset($options['brand_voice_id'])
            ? BrandVoice::find($options['brand_voice_id'])
            : $this->getDefaultBrandVoice($review->location->tenant_id);

        $customInstructions = $options['custom_instructions'] ?? null;
        $maxLength = $options['max_length'] ?? null;
        $useSentimentContext = $options['use_sentiment_context'] ?? false;
        $includeLocationContext = $options['include_location_context'] ?? false;
        $autoDetectLanguage = $options['auto_detect_language'] ?? false;
        $includeQualityScore = $options['include_quality_score'] ?? false;

        $prompt = $this->buildResponsePrompt(
            $review,
            $tone,
            $language,
            $brandVoice,
            $customInstructions,
            $maxLength,
            $useSentimentContext,
            $includeLocationContext,
            $autoDetectLanguage
        );

        try {
            $httpResponse = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional customer service representative writing responses to customer reviews. Generate helpful, appropriate responses.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);

            if ($httpResponse->status() === 429) {
                throw new \RuntimeException('AI service rate limit exceeded. Please try again later.');
            }

            if (! $httpResponse->successful()) {
                Log::error('OpenRouter API error for AI response', [
                    'review_id' => $review->id,
                    'status' => $httpResponse->status(),
                    'error' => $httpResponse->json('error'),
                ]);
                throw new \RuntimeException('AI service temporarily unavailable');
            }

            $content = $httpResponse->json('choices.0.message.content');

            if (empty($content)) {
                throw new \RuntimeException('AI service returned empty response');
            }

            // Save to history
            AIResponseHistory::create([
                'review_id' => $review->id,
                'user_id' => $user->id,
                'content' => $content,
                'tone' => $tone,
                'language' => $language,
                'brand_voice_id' => $brandVoice?->id,
                'metadata' => [
                    'custom_instructions' => $customInstructions,
                    'use_sentiment_context' => $useSentimentContext,
                    'include_location_context' => $includeLocationContext,
                ],
            ]);

            // Create or update the response
            $reviewResponse = $review->response()->updateOrCreate(
                ['review_id' => $review->id],
                [
                    'user_id' => $user->id,
                    'content' => $content,
                    'status' => 'draft',
                    'ai_generated' => true,
                    'brand_voice_id' => $brandVoice?->id,
                    'tone' => $tone,
                    'language' => $language,
                ]
            );

            $result = ['response' => $reviewResponse->fresh()];

            if ($includeQualityScore) {
                $quality = $this->assessQuality($content, $review);
                $result['quality_score'] = $quality['score'];
                $result['quality_feedback'] = $quality['feedback'];
            }

            return $result;
        } catch (ConnectionException $e) {
            Log::error('AI response connection error', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('AI service temporarily unavailable');
        }
    }

    /** @return array{generated_count: int, skipped_count: int, failed_count: int, responses: array} */
    public function generateBulkResponses(
        array $reviewIds,
        User $user,
        array $options = []
    ): array {
        $force = $options['force'] ?? false;
        $results = [
            'generated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'responses' => [],
        ];

        foreach ($reviewIds as $reviewId) {
            $review = Review::find($reviewId);

            if (! $review) {
                $results['failed_count']++;

                continue;
            }

            // Skip if response exists and force is false
            if (! $force && $review->response()->exists()) {
                $results['skipped_count']++;

                continue;
            }

            try {
                $result = $this->generateResponse($review, $user, $options);

                if ($result) {
                    $results['generated_count']++;
                    $results['responses'][] = [
                        'review_id' => $reviewId,
                        'content' => $result['response']->content,
                        'status' => $result['response']->status,
                        'tone' => $result['response']->tone,
                    ];
                } else {
                    $results['failed_count']++;
                }
            } catch (\Exception $e) {
                $results['failed_count']++;
                Log::error('Bulk AI response generation failed', [
                    'review_id' => $reviewId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /** @return array{generated_count: int, skipped_count: int, failed_count: int, responses: array} */
    public function generateLocationResponses(
        Location $location,
        User $user,
        array $options = []
    ): array {
        $reviewIds = $location->reviews()
            ->when(! ($options['force'] ?? false), fn ($q) => $q->whereDoesntHave('response'))
            ->pluck('id')
            ->toArray();

        return $this->generateBulkResponses($reviewIds, $user, $options);
    }

    /** @return array{response: ReviewResponse}|null */
    public function regenerateResponse(
        Review $review,
        User $user,
        array $options = []
    ): ?array {
        // Delete existing response
        $review->response()->delete();

        return $this->generateResponse($review, $user, $options);
    }

    /** @return Collection<int, AIResponseHistory> */
    public function getResponseHistory(Review $review): Collection
    {
        return $review->aiResponseHistories()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /** @return array{suggestions: array, improved_version: string}|null */
    public function getSuggestions(ReviewResponse $response): ?array
    {
        $apiKey = config('openrouter.api_key');

        if (empty($apiKey)) {
            return null;
        }

        $prompt = <<<PROMPT
Analyze this customer review response and provide suggestions for improvement.

Response: {$response->content}

Return a JSON object with:
{
    "suggestions": ["suggestion 1", "suggestion 2", ...],
    "improved_version": "An improved version of the response"
}

Focus on:
- Personalization
- Warmth and empathy
- Clear call-to-action
- Professional tone

Return ONLY valid JSON.
PROMPT;

        try {
            $httpResponse = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.5,
            ]);

            if (! $httpResponse->successful()) {
                return null;
            }

            $content = $httpResponse->json('choices.0.message.content');
            $content = $this->extractJson($content);

            return json_decode($content, true);
        } catch (\Exception $e) {
            Log::error('AI suggestions error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, mixed> */
    public function getStats(Tenant $tenant, ?Location $location = null): array
    {
        $locationIds = $location
            ? [$location->id]
            : $tenant->locations()->pluck('id')->toArray();

        $query = ReviewResponse::query()
            ->whereHas('review', fn ($q) => $q->whereIn('location_id', $locationIds));

        $total = $query->count();
        $aiGenerated = (clone $query)->where('ai_generated', true)->count();
        $humanWritten = $total - $aiGenerated;

        $byStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byTone = (clone $query)
            ->where('ai_generated', true)
            ->select('tone', DB::raw('count(*) as count'))
            ->groupBy('tone')
            ->pluck('count', 'tone')
            ->toArray();

        return [
            'total_responses' => $total,
            'ai_generated_count' => $aiGenerated,
            'human_written_count' => $humanWritten,
            'ai_percentage' => $total > 0 ? round($aiGenerated / $total * 100, 1) : 0,
            'by_status' => [
                'draft' => $byStatus['draft'] ?? 0,
                'approved' => $byStatus['approved'] ?? 0,
                'rejected' => $byStatus['rejected'] ?? 0,
                'published' => $byStatus['published'] ?? 0,
            ],
            'by_tone' => $byTone,
        ];
    }

    /** @return Collection<int, array> */
    public function getUsageOverTime(Tenant $tenant, array $filters = []): Collection
    {
        $locationIds = $tenant->locations()->pluck('id')->toArray();

        $query = ReviewResponse::query()
            ->whereHas('review', fn ($q) => $q->whereIn('location_id', $locationIds))
            ->where('ai_generated', true);

        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $responses = $query->get();

        // Group by date in PHP for SQLite compatibility
        return $responses->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))
            ->map(function ($items, $date) {
                return [
                    'date' => $date,
                    'generated_count' => $items->count(),
                    'approved_count' => $items->where('status', 'approved')->count(),
                    'published_count' => $items->where('status', 'published')->count(),
                ];
            })
            ->sortKeys()
            ->values();
    }

    protected function getDefaultBrandVoice(int $tenantId): ?BrandVoice
    {
        return BrandVoice::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first();
    }

    protected function buildResponsePrompt(
        Review $review,
        string $tone,
        string $language,
        ?BrandVoice $brandVoice,
        ?string $customInstructions,
        ?int $maxLength,
        bool $useSentimentContext,
        bool $includeLocationContext,
        bool $autoDetectLanguage
    ): string {
        $reviewContent = $review->content ?? '(No text content, rating only)';
        $rating = $review->rating;

        $toneInstructions = match ($tone) {
            'friendly' => 'Be warm, casual, and approachable. Use friendly language.',
            'apologetic' => 'Express sincere apology. Take responsibility. Offer to make things right.',
            'empathetic' => 'Show understanding and compassion. Acknowledge the customer\'s feelings.',
            default => 'Be professional, courteous, and helpful.',
        };

        $prompt = "Generate a response to this customer review.\n\n";
        $prompt .= "Review (Rating: {$rating}/5 stars):\n{$reviewContent}\n\n";
        $prompt .= "Tone: {$toneInstructions}\n";

        if ($autoDetectLanguage) {
            $prompt .= "Respond in the same language as the review.\n";
        } else {
            $languageName = $this->getLanguageName($language);
            $prompt .= "Respond in {$languageName}.\n";
        }

        if ($brandVoice) {
            $prompt .= "\nBrand Voice Guidelines:\n{$brandVoice->guidelines}\n";

            if (! empty($brandVoice->example_responses)) {
                $prompt .= "\nExample responses in our brand voice:\n";
                foreach ($brandVoice->example_responses as $example) {
                    $prompt .= "- {$example}\n";
                }
            }
        }

        if ($useSentimentContext && $review->sentiment) {
            $sentiment = $review->sentiment;
            $prompt .= "\nSentiment Analysis Context:\n";
            $prompt .= "- Overall sentiment: {$sentiment->sentiment}\n";
            $prompt .= "- Sentiment score: {$sentiment->sentiment_score}\n";

            if (! empty($sentiment->topics)) {
                $prompt .= "- Topics mentioned:\n";
                foreach ($sentiment->topics as $topic) {
                    $topicName = $topic['topic'];
                    $topicSentiment = $topic['sentiment'] ?? 'neutral';
                    $prompt .= "  * {$topicName} ({$topicSentiment})\n";
                }
            }

            if (! empty($sentiment->emotions)) {
                $topEmotions = collect($sentiment->emotions)
                    ->sortDesc()
                    ->take(3)
                    ->keys()
                    ->implode(', ');
                $prompt .= "- Key emotions: {$topEmotions}\n";
            }
        }

        if ($includeLocationContext) {
            $location = $review->location;
            $prompt .= "\nLocation Context:\n";
            $prompt .= "- Business name: {$location->name}\n";

            if ($location->address) {
                $prompt .= "- Address: {$location->address}\n";
            }
        }

        if ($customInstructions) {
            $prompt .= "\nAdditional Instructions:\n{$customInstructions}\n";
        }

        if ($maxLength) {
            $prompt .= "\nKeep the response under {$maxLength} characters.\n";
        }

        $prompt .= "\nGenerate only the response text, no additional commentary.";

        return $prompt;
    }

    /** @return array{score: float, feedback: string} */
    protected function assessQuality(string $content, Review $review): array
    {
        $score = 0.7; // Base score
        $feedback = [];

        // Length check
        $length = strlen($content);
        if ($length < 50) {
            $score -= 0.1;
            $feedback[] = 'Response is too short';
        } elseif ($length > 100) {
            $score += 0.1;
        }

        // Personalization check
        if (stripos($content, 'thank') !== false) {
            $score += 0.05;
        }

        // Addresses negative reviews appropriately
        if ($review->rating <= 2 && stripos($content, 'sorry') !== false) {
            $score += 0.1;
        }

        // Call to action
        if (preg_match('/visit|come back|see you|contact/i', $content)) {
            $score += 0.05;
        }

        $score = min(1.0, max(0.0, $score));

        return [
            'score' => round($score, 2),
            'feedback' => empty($feedback) ? 'Response looks good!' : implode('. ', $feedback),
        ];
    }

    protected function getLanguageName(string $code): string
    {
        $languages = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'cs' => 'Czech',
        ];

        return $languages[$code] ?? 'English';
    }

    protected function extractJson(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\n?/', '', $content);
            $content = preg_replace('/\n?```$/', '', $content);
        }

        return $content;
    }
}
