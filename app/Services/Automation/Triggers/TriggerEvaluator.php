<?php

declare(strict_types=1);

namespace App\Services\Automation\Triggers;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Services\Automation\AutomationService;
use Illuminate\Support\Facades\Log;

class TriggerEvaluator
{
    /**
     * Handle review received trigger
     */
    public function handleReviewReceived(Review $review): void
    {
        $triggerData = [
            'review_id' => $review->id,
            'location_id' => $review->location_id,
            'tenant_id' => $review->location->tenant_id,
            'platform' => $review->platform,
            'rating' => $review->rating,
            'content' => $review->content,
            'author_name' => $review->author_name,
        ];

        $automationService = app(AutomationService::class);

        // Trigger general review received workflows
        $automationService->trigger(
            AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            $triggerData,
            "review:{$review->id}"
        );

        // Trigger rating-specific workflows
        if ($review->rating <= 3) {
            $automationService->trigger(
                AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
                $triggerData,
                "review:{$review->id}"
            );
        } elseif ($review->rating >= 4) {
            $automationService->trigger(
                AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
                $triggerData,
                "review:{$review->id}"
            );
        }

        Log::info('Review triggers processed', [
            'review_id' => $review->id,
            'rating' => $review->rating,
            'platform' => $review->platform,
        ]);
    }

    /**
     * Handle sentiment analysis completed trigger
     */
    public function handleSentimentAnalyzed(ReviewSentiment $sentiment): void
    {
        $review = $sentiment->review;
        
        $triggerData = [
            'review_id' => $review->id,
            'location_id' => $review->location_id,
            'tenant_id' => $review->location->tenant_id,
            'sentiment' => $sentiment->sentiment,
            'sentiment_score' => $sentiment->sentiment_score,
            'emotions' => $sentiment->emotions,
            'topics' => $sentiment->topics,
            'keywords' => $sentiment->keywords,
        ];

        $automationService = app(AutomationService::class);

        // Trigger negative sentiment workflows
        if ($sentiment->sentiment === 'negative' || $sentiment->sentiment_score <= 0.3) {
            $automationService->trigger(
                AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
                $triggerData,
                "sentiment:{$sentiment->id}"
            );
        }

        Log::info('Sentiment triggers processed', [
            'review_id' => $review->id,
            'sentiment' => $sentiment->sentiment,
            'sentiment_score' => $sentiment->sentiment_score,
        ]);
    }

    /**
     * Handle listing discrepancy detected trigger
     */
    public function handleListingDiscrepancy(array $discrepancyData): void
    {
        $triggerData = array_merge($discrepancyData, [
            'trigger_type' => 'listing_discrepancy',
        ]);

        $automationService = app(AutomationService::class);

        $automationService->trigger(
            AutomationWorkflow::TRIGGER_LISTING_DISCREPANCY,
            $triggerData,
            "listing_discrepancy:{$discrepancyData['location_id']}"
        );

        Log::info('Listing discrepancy triggers processed', [
            'location_id' => $discrepancyData['location_id'],
            'discrepancies' => count($discrepancyData['discrepancies'] ?? []),
        ]);
    }

    /**
     * Handle scheduled trigger execution
     */
    public function handleScheduledTrigger(string $schedule, array $contextData = []): void
    {
        $triggerData = array_merge($contextData, [
            'schedule' => $schedule,
            'triggered_at' => now()->toISOString(),
        ]);

        $automationService = app(AutomationService::class);

        $automationService->trigger(
            AutomationWorkflow::TRIGGER_SCHEDULED,
            $triggerData,
            "scheduled:{$schedule}"
        );

        Log::info('Scheduled triggers processed', [
            'schedule' => $schedule,
            'context_keys' => array_keys($contextData),
        ]);
    }

    /**
     * Handle manual trigger execution
     */
    public function handleManualTrigger(int $workflowId, array $contextData, int $userId): void
    {
        $workflow = AutomationWorkflow::find($workflowId);
        
        if (!$workflow || !$workflow->canExecute()) {
            throw new \RuntimeException("Workflow {$workflowId} cannot be executed");
        }

        $triggerData = array_merge($contextData, [
            'triggered_by_user_id' => $userId,
            'triggered_at' => now()->toISOString(),
            'tenant_id' => $workflow->tenant_id,
        ]);

        $automationService = app(AutomationService::class);

        // Execute the specific workflow directly
        $automationService->executeWorkflow(
            $workflow,
            $triggerData,
            "manual:{$userId}"
        );

        Log::info('Manual trigger executed', [
            'workflow_id' => $workflowId,
            'user_id' => $userId,
        ]);
    }
}