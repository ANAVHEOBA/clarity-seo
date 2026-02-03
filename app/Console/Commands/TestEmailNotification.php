<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Console\Command;

class TestEmailNotification extends Command
{
    protected $signature = 'test:email-notification {review_id?}';
    protected $description = 'Test email notification automation';

    public function handle()
    {
        $reviewId = $this->argument('review_id');
        
        if ($reviewId) {
            $review = Review::find($reviewId);
            if (!$review) {
                $this->error("Review with ID {$reviewId} not found");
                return 1;
            }
        } else {
            // Find a review with rating < 4 for testing
            $review = Review::where('rating', '<', 4)->latest()->first();
            if (!$review) {
                $this->error("No low-rating reviews found for testing");
                return 1;
            }
        }

        $this->info("Testing automation with review:");
        $this->info("- ID: {$review->id}");
        $this->info("- Rating: {$review->rating}");
        $this->info("- Platform: {$review->platform}");
        $this->info("- Content: " . substr($review->content ?? 'No content', 0, 50) . '...');

        try {
            $triggerEvaluator = app(TriggerEvaluator::class);
            $triggerEvaluator->handleReviewReceived($review);
            
            $this->info("✅ Automation triggered successfully!");
            $this->info("Check your email and the logs for results.");
            
        } catch (\Exception $e) {
            $this->error("❌ Automation failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}