<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->outsider = User::factory()->create();

    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->owner, ['role' => 'owner']);
    $this->tenant->users()->attach($this->admin, ['role' => 'admin']);
    $this->tenant->users()->attach($this->member, ['role' => 'member']);

    $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
});

/*
|--------------------------------------------------------------------------
| Single Review Sentiment Analysis
|--------------------------------------------------------------------------
*/

describe('Single Review Analysis', function () {
    it('analyzes sentiment for a single review', function () {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'sentiment' => 'positive',
                            'sentiment_score' => 0.85,
                            'emotions' => ['satisfied' => 0.8, 'happy' => 0.6],
                            'topics' => [
                                ['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.9],
                                ['topic' => 'staff', 'sentiment' => 'positive', 'score' => 0.85],
                            ],
                            'keywords' => ['friendly', 'helpful', 'quick'],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'The staff was incredibly friendly and helpful. Quick service!',
            'rating' => 5,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'review_id',
                'sentiment',
                'sentiment_score',
                'emotions',
                'topics',
                'keywords',
                'analyzed_at',
            ],
        ]);
        expect($response->json('data.sentiment'))->toBe('positive');
        expect($response->json('data.sentiment_score'))->toBeGreaterThan(0.5);
    });

    it('requires authentication to analyze review', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertUnauthorized();
    });

    it('requires tenant membership to analyze review', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->outsider)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertForbidden();
    });

    it('allows owners to analyze reviews', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'neutral',
                    'sentiment_score' => 0.5,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
    });

    it('allows admins to analyze reviews', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'neutral',
                    'sentiment_score' => 0.5,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
    });

    it('denies members from analyzing reviews', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->member)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertForbidden();
    });

    it('returns 404 for review in different tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        $review = Review::factory()->create(['location_id' => $otherLocation->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertNotFound();
    });

    it('updates existing sentiment when re-analyzing', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.9,
                    'emotions' => ['happy' => 0.9],
                    'topics' => [],
                    'keywords' => ['excellent'],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        // Create existing sentiment
        $existingSentiment = ReviewSentiment::factory()->create([
            'review_id' => $review->id,
            'sentiment' => 'negative',
            'sentiment_score' => 0.2,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
        expect($response->json('data.sentiment'))->toBe('positive');
        expect(ReviewSentiment::where('review_id', $review->id)->count())->toBe(1);
    });

    it('handles reviews with empty content gracefully', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'neutral',
                    'sentiment_score' => 0.5,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => null,
            'rating' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
        expect($response->json('data.sentiment'))->toBe('neutral');
    });

    it('handles API failure gracefully', function () {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => 'Rate limited'], 429)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertStatus(503);
        $response->assertJson(['message' => 'Sentiment analysis service unavailable']);
    });
});

/*
|--------------------------------------------------------------------------
| Bulk Location Sentiment Analysis
|--------------------------------------------------------------------------
*/

describe('Bulk Location Analysis', function () {
    it('analyzes all unanalyzed reviews for a location', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.8,
                    'emotions' => ['satisfied' => 0.7],
                    'topics' => [['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.8]],
                    'keywords' => ['great'],
                ])],
            ]],
        ], 200)]);

        Review::factory()->count(5)->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/analyze");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'analyzed_count',
                'skipped_count',
                'failed_count',
            ],
        ]);
        expect($response->json('data.analyzed_count'))->toBe(5);
    });

    it('skips already analyzed reviews by default', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'neutral',
                    'sentiment_score' => 0.5,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $reviews = Review::factory()->count(3)->create(['location_id' => $this->location->id]);

        // Pre-analyze one review
        ReviewSentiment::factory()->create(['review_id' => $reviews[0]->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/analyze");

        $response->assertSuccessful();
        expect($response->json('data.analyzed_count'))->toBe(2);
        expect($response->json('data.skipped_count'))->toBe(1);
    });

    it('can force re-analyze all reviews', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.85,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $reviews = Review::factory()->count(3)->create(['location_id' => $this->location->id]);

        // Pre-analyze all reviews
        foreach ($reviews as $review) {
            ReviewSentiment::factory()->create(['review_id' => $review->id, 'sentiment' => 'negative']);
        }

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/analyze", [
                'force' => true,
            ]);

        $response->assertSuccessful();
        expect($response->json('data.analyzed_count'))->toBe(3);
        expect($response->json('data.skipped_count'))->toBe(0);
    });

    it('requires admin or owner to bulk analyze', function () {
        $response = $this->actingAs($this->member)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/analyze");

        $response->assertForbidden();
    });

    it('returns 404 for location in different tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$otherLocation->id}/sentiment/analyze");

        $response->assertNotFound();
    });

    it('handles partial failures in bulk analysis', function () {
        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 2) {
                return Http::response(['error' => 'Rate limited'], 429);
            }

            return Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'sentiment' => 'positive',
                        'sentiment_score' => 0.8,
                        'emotions' => [],
                        'topics' => [],
                        'keywords' => [],
                    ])],
                ]],
            ], 200);
        });

        Review::factory()->count(3)->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/analyze");

        $response->assertSuccessful();
        expect($response->json('data.analyzed_count'))->toBe(2);
        expect($response->json('data.failed_count'))->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Get Sentiment Data
|--------------------------------------------------------------------------
*/

describe('Get Review Sentiment', function () {
    it('returns sentiment for a single review', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        $sentiment = ReviewSentiment::factory()->create([
            'review_id' => $review->id,
            'sentiment' => 'positive',
            'sentiment_score' => 0.85,
            'emotions' => ['happy' => 0.8, 'satisfied' => 0.9],
            'topics' => [
                ['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.9],
            ],
            'keywords' => ['excellent', 'friendly', 'recommended'],
        ]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'review_id',
                'sentiment',
                'sentiment_score',
                'emotions',
                'topics',
                'keywords',
                'analyzed_at',
            ],
        ]);
        expect($response->json('data.sentiment'))->toBe('positive');
        expect($response->json('data.emotions.happy'))->toBe(0.8);
    });

    it('returns 404 for review without sentiment analysis', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertNotFound();
        $response->assertJson(['message' => 'Sentiment analysis not found for this review']);
    });

    it('requires authentication to view sentiment', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertUnauthorized();
    });

    it('requires tenant membership to view sentiment', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $review->id]);

        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertForbidden();
    });

    it('allows all tenant members to view sentiment', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $review->id]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Aggregated Sentiment Statistics
|--------------------------------------------------------------------------
*/

describe('Aggregated Sentiment Statistics', function () {
    beforeEach(function () {
        // Create reviews with sentiments - explicit dates and platform for predictable filtering
        $this->positiveReviews = Review::factory()->count(5)->create([
            'location_id' => $this->location->id,
            'rating' => 5,
            'platform' => 'google',
            'published_at' => now(),
        ]);
        foreach ($this->positiveReviews as $review) {
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'sentiment' => 'positive',
                'sentiment_score' => fake()->randomFloat(2, 0.7, 1.0),
            ]);
        }

        $this->negativeReviews = Review::factory()->count(2)->create([
            'location_id' => $this->location->id,
            'rating' => 2,
            'platform' => 'google',
            'published_at' => now(),
        ]);
        foreach ($this->negativeReviews as $review) {
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'sentiment' => 'negative',
                'sentiment_score' => fake()->randomFloat(2, 0.0, 0.3),
            ]);
        }

        $this->neutralReviews = Review::factory()->count(3)->create([
            'location_id' => $this->location->id,
            'rating' => 3,
            'platform' => 'google',
            'published_at' => now(),
        ]);
        foreach ($this->neutralReviews as $review) {
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'sentiment' => 'neutral',
                'sentiment_score' => fake()->randomFloat(2, 0.4, 0.6),
            ]);
        }
    });

    it('returns aggregated sentiment for tenant', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'total_analyzed',
                'sentiment_distribution' => [
                    'positive',
                    'negative',
                    'neutral',
                    'mixed',
                ],
                'average_sentiment_score',
                'sentiment_percentages' => [
                    'positive',
                    'negative',
                    'neutral',
                    'mixed',
                ],
            ],
        ]);
        expect($response->json('data.total_analyzed'))->toBe(10);
        expect($response->json('data.sentiment_distribution.positive'))->toBe(5);
        expect($response->json('data.sentiment_distribution.negative'))->toBe(2);
        expect($response->json('data.sentiment_distribution.neutral'))->toBe(3);
    });

    it('returns aggregated sentiment for specific location', function () {
        // Create another location with different sentiment
        $otherLocation = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherReview = Review::factory()->create(['location_id' => $otherLocation->id]);
        ReviewSentiment::factory()->create(['review_id' => $otherReview->id, 'sentiment' => 'negative']);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment");

        $response->assertSuccessful();
        expect($response->json('data.total_analyzed'))->toBe(10); // Only this location
    });

    it('filters sentiment by date range', function () {
        // Create old review
        $oldReview = Review::factory()->create([
            'location_id' => $this->location->id,
            'published_at' => now()->subMonths(3),
        ]);
        ReviewSentiment::factory()->create([
            'review_id' => $oldReview->id,
            'sentiment' => 'negative',
        ]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment?".http_build_query([
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]));

        $response->assertSuccessful();
        expect($response->json('data.total_analyzed'))->toBe(10); // Excludes old review
    });

    it('filters sentiment by platform', function () {
        // Update some reviews to different platform
        $this->positiveReviews[0]->update(['platform' => 'yelp']);
        $this->positiveReviews[1]->update(['platform' => 'yelp']);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment?platform=google");

        $response->assertSuccessful();
        expect($response->json('data.total_analyzed'))->toBe(8);
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment");

        $response->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Topic Analysis
|--------------------------------------------------------------------------
*/

describe('Topic Analysis', function () {
    beforeEach(function () {
        $topics = [
            [
                ['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.9],
                ['topic' => 'staff', 'sentiment' => 'positive', 'score' => 0.85],
            ],
            [
                ['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.8],
                ['topic' => 'price', 'sentiment' => 'negative', 'score' => 0.3],
            ],
            [
                ['topic' => 'cleanliness', 'sentiment' => 'positive', 'score' => 0.95],
                ['topic' => 'staff', 'sentiment' => 'negative', 'score' => 0.2],
            ],
            [
                ['topic' => 'service', 'sentiment' => 'negative', 'score' => 0.25],
                ['topic' => 'wait_time', 'sentiment' => 'negative', 'score' => 0.1],
            ],
            [
                ['topic' => 'location', 'sentiment' => 'positive', 'score' => 0.88],
                ['topic' => 'price', 'sentiment' => 'positive', 'score' => 0.75],
            ],
        ];

        foreach ($topics as $topicSet) {
            $review = Review::factory()->create(['location_id' => $this->location->id]);
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'topics' => $topicSet,
            ]);
        }
    });

    it('returns topic frequency for tenant', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'topic',
                    'count',
                    'positive_count',
                    'negative_count',
                    'neutral_count',
                    'average_score',
                ],
            ],
        ]);

        $topics = collect($response->json('data'));
        $serviceTopic = $topics->firstWhere('topic', 'service');
        expect($serviceTopic['count'])->toBe(3);
    });

    it('returns topic frequency for specific location', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/topics");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBeGreaterThan(0);
    });

    it('sorts topics by frequency by default', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics");

        $response->assertSuccessful();
        $topics = $response->json('data');

        // service appears 3 times, should be first or near top
        expect($topics[0]['count'])->toBeGreaterThanOrEqual($topics[1]['count'] ?? 0);
    });

    it('can sort topics by sentiment score', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics?sort=score&direction=desc");

        $response->assertSuccessful();
    });

    it('filters topics by date range', function () {
        // Create old review with different topic
        $oldReview = Review::factory()->create([
            'location_id' => $this->location->id,
            'published_at' => now()->subMonths(3),
        ]);
        ReviewSentiment::factory()->create([
            'review_id' => $oldReview->id,
            'topics' => [['topic' => 'outdated_topic', 'sentiment' => 'positive', 'score' => 0.9]],
        ]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics?".http_build_query([
                'from' => now()->subMonth()->toDateString(),
            ]));

        $response->assertSuccessful();
        $topics = collect($response->json('data'));
        expect($topics->firstWhere('topic', 'outdated_topic'))->toBeNull();
    });

    it('limits number of topics returned', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics?limit=3");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBeLessThanOrEqual(3);
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/topics");

        $response->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Keyword Analysis
|--------------------------------------------------------------------------
*/

describe('Keyword Analysis', function () {
    beforeEach(function () {
        $keywordSets = [
            ['friendly', 'helpful', 'quick', 'recommended'],
            ['friendly', 'professional', 'clean'],
            ['slow', 'expensive', 'disappointed'],
            ['helpful', 'quick', 'amazing'],
            ['friendly', 'great', 'recommended', 'clean'],
        ];

        foreach ($keywordSets as $keywords) {
            $review = Review::factory()->create(['location_id' => $this->location->id]);
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'keywords' => $keywords,
            ]);
        }
    });

    it('returns keyword frequency for tenant', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'keyword',
                    'count',
                    'percentage',
                ],
            ],
        ]);

        $keywords = collect($response->json('data'));
        $friendlyKeyword = $keywords->firstWhere('keyword', 'friendly');
        expect($friendlyKeyword['count'])->toBe(3);
    });

    it('returns keyword frequency for specific location', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/keywords");

        $response->assertSuccessful();
    });

    it('sorts keywords by frequency by default', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords");

        $response->assertSuccessful();
        $keywords = $response->json('data');

        for ($i = 0; $i < count($keywords) - 1; $i++) {
            expect($keywords[$i]['count'])->toBeGreaterThanOrEqual($keywords[$i + 1]['count']);
        }
    });

    it('can sort keywords alphabetically', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords?sort=keyword&direction=asc");

        $response->assertSuccessful();
    });

    it('filters keywords by minimum count', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords?min_count=2");

        $response->assertSuccessful();
        $keywords = $response->json('data');

        foreach ($keywords as $keyword) {
            expect($keyword['count'])->toBeGreaterThanOrEqual(2);
        }
    });

    it('limits number of keywords returned', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords?limit=5");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBeLessThanOrEqual(5);
    });

    it('filters keywords by date range', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords?".http_build_query([
                'from' => now()->subMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]));

        $response->assertSuccessful();
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/keywords");

        $response->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Sentiment Trends
|--------------------------------------------------------------------------
*/

describe('Sentiment Trends', function () {
    beforeEach(function () {
        // Create reviews spread across different dates
        $dates = [
            now()->subDays(30),
            now()->subDays(28),
            now()->subDays(20),
            now()->subDays(15),
            now()->subDays(10),
            now()->subDays(7),
            now()->subDays(5),
            now()->subDays(3),
            now()->subDays(1),
            now(),
        ];

        $sentiments = ['positive', 'positive', 'negative', 'positive', 'neutral', 'positive', 'negative', 'positive', 'positive', 'neutral'];

        foreach ($dates as $index => $date) {
            $review = Review::factory()->create([
                'location_id' => $this->location->id,
                'published_at' => $date,
            ]);
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'sentiment' => $sentiments[$index],
                'sentiment_score' => match ($sentiments[$index]) {
                    'positive' => fake()->randomFloat(2, 0.7, 1.0),
                    'negative' => fake()->randomFloat(2, 0.0, 0.3),
                    'neutral' => fake()->randomFloat(2, 0.4, 0.6),
                },
                'analyzed_at' => $date,
            ]);
        }
    });

    it('returns daily sentiment trends for tenant', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends?group_by=day");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'date',
                    'total',
                    'positive',
                    'negative',
                    'neutral',
                    'mixed',
                    'average_score',
                ],
            ],
        ]);
    });

    it('returns weekly sentiment trends', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends?group_by=week");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBeLessThanOrEqual(5); // ~30 days = ~5 weeks max
    });

    it('returns monthly sentiment trends', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends?group_by=month");

        $response->assertSuccessful();
    });

    it('returns trends for specific location', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/trends");

        $response->assertSuccessful();
    });

    it('filters trends by date range', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends?".http_build_query([
                'from' => now()->subDays(14)->toDateString(),
                'to' => now()->toDateString(),
            ]));

        $response->assertSuccessful();
        // Should only include reviews from last 14 days
    });

    it('filters trends by platform', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends?platform=google");

        $response->assertSuccessful();
    });

    it('uses daily grouping by default', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends");

        $response->assertSuccessful();
        // Dates should be in Y-m-d format for daily
        $firstDate = $response->json('data.0.date');
        expect($firstDate)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/trends");

        $response->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Emotion Analysis
|--------------------------------------------------------------------------
*/

describe('Emotion Analysis', function () {
    beforeEach(function () {
        $emotionSets = [
            ['happy' => 0.8, 'satisfied' => 0.9, 'grateful' => 0.6],
            ['frustrated' => 0.7, 'disappointed' => 0.8],
            ['happy' => 0.9, 'excited' => 0.7],
            ['angry' => 0.6, 'frustrated' => 0.8, 'disappointed' => 0.5],
            ['satisfied' => 0.85, 'relieved' => 0.6],
        ];

        foreach ($emotionSets as $emotions) {
            $review = Review::factory()->create(['location_id' => $this->location->id]);
            ReviewSentiment::factory()->create([
                'review_id' => $review->id,
                'emotions' => $emotions,
            ]);
        }
    });

    it('returns emotion frequency for tenant', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'emotion',
                    'count',
                    'average_intensity',
                    'percentage',
                ],
            ],
        ]);

        $emotions = collect($response->json('data'));
        $frustratedEmotion = $emotions->firstWhere('emotion', 'frustrated');
        expect($frustratedEmotion['count'])->toBe(2);
    });

    it('returns emotion frequency for specific location', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment/emotions");

        $response->assertSuccessful();
    });

    it('sorts emotions by frequency by default', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions");

        $response->assertSuccessful();
        $emotions = $response->json('data');

        for ($i = 0; $i < count($emotions) - 1; $i++) {
            expect($emotions[$i]['count'])->toBeGreaterThanOrEqual($emotions[$i + 1]['count']);
        }
    });

    it('can filter positive emotions only', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions?type=positive");

        $response->assertSuccessful();
        $emotions = collect($response->json('data'))->pluck('emotion')->toArray();

        // Should not contain negative emotions
        expect($emotions)->not->toContain('angry');
        expect($emotions)->not->toContain('frustrated');
    });

    it('can filter negative emotions only', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions?type=negative");

        $response->assertSuccessful();
        $emotions = collect($response->json('data'))->pluck('emotion')->toArray();

        // Should not contain positive emotions
        expect($emotions)->not->toContain('happy');
        expect($emotions)->not->toContain('satisfied');
    });

    it('limits number of emotions returned', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions?limit=3");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBeLessThanOrEqual(3);
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions");

        $response->assertUnauthorized();
    });

    it('requires tenant membership', function () {
        $response = $this->actingAs($this->outsider)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/emotions");

        $response->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| Comparison & Benchmarking
|--------------------------------------------------------------------------
*/

describe('Location Comparison', function () {
    it('compares sentiment across multiple locations', function () {
        $location2 = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        $location3 = Location::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create reviews for each location
        foreach ([$this->location, $location2, $location3] as $index => $location) {
            $reviews = Review::factory()->count(3)->create(['location_id' => $location->id]);
            $sentiment = $index === 0 ? 'positive' : ($index === 1 ? 'neutral' : 'negative');
            foreach ($reviews as $review) {
                ReviewSentiment::factory()->create([
                    'review_id' => $review->id,
                    'sentiment' => $sentiment,
                ]);
            }
        }

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/compare?".http_build_query([
                'location_ids' => [$this->location->id, $location2->id, $location3->id],
            ]));

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'location_id',
                    'location_name',
                    'total_analyzed',
                    'sentiment_distribution',
                    'average_score',
                ],
            ],
        ]);
        expect(count($response->json('data')))->toBe(3);
    });

    it('requires at least 2 locations for comparison', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/compare?".http_build_query([
                'location_ids' => [$this->location->id],
            ]));

        $response->assertStatus(422);
    });

    it('validates locations belong to tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $otherLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/compare?".http_build_query([
                'location_ids' => [$this->location->id, $otherLocation->id],
            ]));

        $response->assertStatus(422);
    });

    it('requires authentication', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/compare");

        $response->assertUnauthorized();
    });
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

describe('Edge Cases', function () {
    it('handles location with no analyzed reviews', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/locations/{$this->location->id}/sentiment");

        $response->assertSuccessful();
        expect($response->json('data.total_analyzed'))->toBe(0);
    });

    it('handles tenant with no locations', function () {
        $emptyTenant = Tenant::factory()->create();
        $emptyTenant->users()->attach($this->owner, ['role' => 'owner']);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/tenants/{$emptyTenant->id}/sentiment");

        $response->assertSuccessful();
        expect($response->json('data.total_analyzed'))->toBe(0);
    });

    it('handles very long review content', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'mixed',
                    'sentiment_score' => 0.5,
                    'emotions' => ['confused' => 0.6],
                    'topics' => [['topic' => 'service', 'sentiment' => 'mixed', 'score' => 0.5]],
                    'keywords' => ['long', 'detailed'],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => str_repeat('This is a very detailed review. ', 500),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
    });

    it('handles reviews with special characters', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.75,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => "Great service! 👍 Très bien! 很好！ <script>alert('xss')</script>",
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
    });

    it('handles reviews in non-English languages', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.9,
                    'emotions' => ['happy' => 0.8],
                    'topics' => [['topic' => 'service', 'sentiment' => 'positive', 'score' => 0.9]],
                    'keywords' => ['excelente', 'servicio'],
                    'language' => 'es',
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'content' => 'Excelente servicio, muy recomendado!',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertSuccessful();
    });

    it('handles concurrent analysis requests for same review', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => json_encode([
                    'sentiment' => 'positive',
                    'sentiment_score' => 0.8,
                    'emotions' => [],
                    'topics' => [],
                    'keywords' => [],
                ])],
            ]],
        ], 200)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        // Simulate concurrent requests
        $response1 = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response2 = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        // Both should succeed, only one sentiment record should exist
        $response1->assertSuccessful();
        $response2->assertSuccessful();
        expect(ReviewSentiment::where('review_id', $review->id)->count())->toBe(1);
    });

    it('handles malformed AI response gracefully', function () {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [[
                'message' => ['content' => 'not valid json'],
            ]],
        ], 200)]);

        $review = Review::factory()->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/analyze");

        $response->assertStatus(503);
        $response->assertJson(['message' => 'Sentiment analysis service unavailable']);
    });

    it('handles empty emotions and topics gracefully', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create([
            'review_id' => $review->id,
            'emotions' => [],
            'topics' => [],
            'keywords' => [],
        ]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$review->id}/sentiment");

        $response->assertSuccessful();
        expect($response->json('data.emotions'))->toBe([]);
        expect($response->json('data.topics'))->toBe([]);
        expect($response->json('data.keywords'))->toBe([]);
    });

    it('handles deleted review gracefully when fetching sentiment', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $review->id]);

        $reviewId = $review->id;
        $review->delete();

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews/{$reviewId}/sentiment");

        $response->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| Data Export
|--------------------------------------------------------------------------
*/

describe('Sentiment Data Export', function () {
    beforeEach(function () {
        $reviews = Review::factory()->count(10)->create(['location_id' => $this->location->id]);
        foreach ($reviews as $review) {
            ReviewSentiment::factory()->create(['review_id' => $review->id]);
        }
    });

    it('exports sentiment data as JSON', function () {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?format=json");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'review_id',
                    'sentiment',
                    'sentiment_score',
                    'emotions',
                    'topics',
                    'keywords',
                    'analyzed_at',
                ],
            ],
        ]);
    });

    it('exports sentiment data as CSV', function () {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?format=csv");

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('filters export by date range', function () {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?".http_build_query([
                'format' => 'json',
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]));

        $response->assertSuccessful();
    });

    it('filters export by location', function () {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?".http_build_query([
                'format' => 'json',
                'location_id' => $this->location->id,
            ]));

        $response->assertSuccessful();
    });

    it('requires admin or owner to export', function () {
        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?format=json");

        $response->assertForbidden();
    });

    it('requires authentication to export', function () {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/sentiment/export?format=json");

        $response->assertUnauthorized();
    });
});

/*
|--------------------------------------------------------------------------
| Review Inclusion in Reviews Endpoint
|--------------------------------------------------------------------------
*/

describe('Sentiment Inclusion in Reviews', function () {
    it('includes sentiment data when fetching reviews with include parameter', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create([
            'review_id' => $review->id,
            'sentiment' => 'positive',
        ]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews?include=sentiment");

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'sentiment' => [
                        'sentiment',
                        'sentiment_score',
                    ],
                ],
            ],
        ]);
    });

    it('does not include sentiment by default', function () {
        $review = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $review->id]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews");

        $response->assertSuccessful();
        expect($response->json('data.0'))->not->toHaveKey('sentiment');
    });

    it('filters reviews by sentiment', function () {
        $positiveReview = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $positiveReview->id, 'sentiment' => 'positive']);

        $negativeReview = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $negativeReview->id, 'sentiment' => 'negative']);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews?sentiment=positive");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBe(1);
        expect($response->json('data.0.id'))->toBe($positiveReview->id);
    });

    it('filters reviews by sentiment score range', function () {
        $highScoreReview = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $highScoreReview->id, 'sentiment_score' => 0.9]);

        $lowScoreReview = Review::factory()->create(['location_id' => $this->location->id]);
        ReviewSentiment::factory()->create(['review_id' => $lowScoreReview->id, 'sentiment_score' => 0.2]);

        $response = $this->actingAs($this->member)
            ->getJson("/api/v1/tenants/{$this->tenant->id}/reviews?min_sentiment_score=0.7");

        $response->assertSuccessful();
        expect(count($response->json('data')))->toBe(1);
        expect($response->json('data.0.id'))->toBe($highScoreReview->id);
    });
});
