<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sentiment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Sentiment\EmotionCollection;
use App\Http\Resources\Sentiment\KeywordCollection;
use App\Http\Resources\Sentiment\LocationComparisonCollection;
use App\Http\Resources\Sentiment\SentimentExportCollection;
use App\Http\Resources\Sentiment\SentimentResource;
use App\Http\Resources\Sentiment\SentimentStatsResource;
use App\Http\Resources\Sentiment\TopicCollection;
use App\Http\Resources\Sentiment\TrendCollection;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Sentiment\SentimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SentimentController extends Controller
{
    public function __construct(protected SentimentService $sentimentService) {}

    /**
     * Analyze sentiment for a single review.
     */
    public function analyzeReview(Request $request, Tenant $tenant, Review $review): JsonResponse
    {
        $this->authorize('update', $tenant);

        if (! $this->reviewBelongsToTenant($review, $tenant)) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $sentiment = $this->sentimentService->analyzeReview($review);

        if (! $sentiment) {
            return response()->json(['message' => 'Sentiment analysis service unavailable'], 503);
        }

        return response()->json(['data' => new SentimentResource($sentiment)]);
    }

    /**
     * Analyze all reviews for a location.
     */
    public function analyzeLocation(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('update', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $force = $request->boolean('force', false);
        $result = $this->sentimentService->analyzeLocationReviews($location, $force);

        return response()->json(['data' => $result]);
    }

    /**
     * Get sentiment for a single review.
     */
    public function showReviewSentiment(Request $request, Tenant $tenant, Review $review): JsonResponse
    {
        $this->authorize('view', $tenant);

        if (! $this->reviewBelongsToTenant($review, $tenant)) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $sentiment = $this->sentimentService->getReviewSentiment($review);

        if (! $sentiment) {
            return response()->json(['message' => 'Sentiment analysis not found for this review'], 404);
        }

        return response()->json(['data' => new SentimentResource($sentiment)]);
    }

    /**
     * Get aggregated sentiment stats for tenant.
     */
    public function stats(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $filters = $request->only(['from', 'to', 'platform']);
        $stats = $this->sentimentService->getAggregatedStats($tenant, null, $filters);

        return response()->json(['data' => new SentimentStatsResource($stats)]);
    }

    /**
     * Get aggregated sentiment stats for a location.
     */
    public function locationStats(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $filters = $request->only(['from', 'to', 'platform']);
        $stats = $this->sentimentService->getAggregatedStats($tenant, $location, $filters);

        return response()->json(['data' => new SentimentStatsResource($stats)]);
    }

    /**
     * Get topic analysis for tenant.
     */
    public function topics(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $filters = $request->only(['from', 'to', 'platform', 'sort', 'direction', 'limit']);
        $topics = $this->sentimentService->getTopics($tenant, null, $filters);

        return response()->json(['data' => new TopicCollection($topics)]);
    }

    /**
     * Get topic analysis for a location.
     */
    public function locationTopics(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $filters = $request->only(['from', 'to', 'platform', 'sort', 'direction', 'limit']);
        $topics = $this->sentimentService->getTopics($tenant, $location, $filters);

        return response()->json(['data' => new TopicCollection($topics)]);
    }

    /**
     * Get keyword analysis for tenant.
     */
    public function keywords(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $filters = $request->only(['from', 'to', 'platform', 'sort', 'direction', 'limit', 'min_count']);
        $keywords = $this->sentimentService->getKeywords($tenant, null, $filters);

        return response()->json(['data' => new KeywordCollection($keywords)]);
    }

    /**
     * Get keyword analysis for a location.
     */
    public function locationKeywords(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $filters = $request->only(['from', 'to', 'platform', 'sort', 'direction', 'limit', 'min_count']);
        $keywords = $this->sentimentService->getKeywords($tenant, $location, $filters);

        return response()->json(['data' => new KeywordCollection($keywords)]);
    }

    /**
     * Get emotion analysis for tenant.
     */
    public function emotions(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $filters = $request->only(['from', 'to', 'platform', 'type', 'limit']);
        $emotions = $this->sentimentService->getEmotions($tenant, null, $filters);

        return response()->json(['data' => new EmotionCollection($emotions)]);
    }

    /**
     * Get emotion analysis for a location.
     */
    public function locationEmotions(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $filters = $request->only(['from', 'to', 'platform', 'type', 'limit']);
        $emotions = $this->sentimentService->getEmotions($tenant, $location, $filters);

        return response()->json(['data' => new EmotionCollection($emotions)]);
    }

    /**
     * Get sentiment trends for tenant.
     */
    public function trends(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $filters = $request->only(['from', 'to', 'platform', 'group_by']);
        $trends = $this->sentimentService->getTrends($tenant, null, $filters);

        return response()->json(['data' => new TrendCollection($trends)]);
    }

    /**
     * Get sentiment trends for a location.
     */
    public function locationTrends(Request $request, Tenant $tenant, Location $location): JsonResponse
    {
        $this->authorize('view', $tenant);

        if ($location->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Location not found'], 404);
        }

        $filters = $request->only(['from', 'to', 'platform', 'group_by']);
        $trends = $this->sentimentService->getTrends($tenant, $location, $filters);

        return response()->json(['data' => new TrendCollection($trends)]);
    }

    /**
     * Compare sentiment across locations.
     */
    public function compare(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $locationIds = $request->input('location_ids', []);

        if (count($locationIds) < 2) {
            return response()->json([
                'message' => 'At least 2 locations required for comparison',
                'errors' => ['location_ids' => ['At least 2 locations required for comparison']],
            ], 422);
        }

        // Validate all locations belong to tenant
        $validLocationIds = Location::where('tenant_id', $tenant->id)
            ->whereIn('id', $locationIds)
            ->pluck('id')
            ->toArray();

        if (count($validLocationIds) !== count($locationIds)) {
            return response()->json([
                'message' => 'One or more locations do not belong to this tenant',
                'errors' => ['location_ids' => ['One or more locations do not belong to this tenant']],
            ], 422);
        }

        $comparison = $this->sentimentService->compareLocations($tenant, $locationIds);

        return response()->json(['data' => new LocationComparisonCollection($comparison)]);
    }

    /**
     * Export sentiment data.
     */
    public function export(Request $request, Tenant $tenant): JsonResponse|StreamedResponse
    {
        $this->authorize('update', $tenant);

        $format = $request->input('format', 'json');
        $filters = $request->only(['from', 'to', 'location_id']);

        $data = $this->sentimentService->exportSentimentData($tenant, $filters);

        if ($format === 'csv') {
            return $this->exportAsCsv($data);
        }

        return response()->json(['data' => new SentimentExportCollection($data)]);
    }

    protected function reviewBelongsToTenant(Review $review, Tenant $tenant): bool
    {
        $locationIds = $tenant->locations()->pluck('id');

        return $locationIds->contains($review->location_id);
    }

    protected function exportAsCsv($data): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sentiment_export.csv"',
        ];

        return response()->stream(function () use ($data) {
            $handle = fopen('php://output', 'w');

            // Headers
            fputcsv($handle, ['review_id', 'sentiment', 'sentiment_score', 'emotions', 'topics', 'keywords', 'analyzed_at']);

            foreach ($data as $row) {
                fputcsv($handle, [
                    $row['review_id'],
                    $row['sentiment'],
                    $row['sentiment_score'],
                    json_encode($row['emotions']),
                    json_encode($row['topics']),
                    json_encode($row['keywords']),
                    $row['analyzed_at'],
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
