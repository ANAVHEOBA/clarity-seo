<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Console\Command;

class TestAutomationCommand extends Command
{
    protected $signature = 'automation:test {--review-id= : Test with specific review ID}';
    protected $description = 'Test the automation system with sample data';

    public function handle(TriggerEvaluator $triggerEvaluator): int
    {
        $this->info('Testing Automation System...');

        $reviewId = $this->option('review-id');
        
        if ($reviewId) {
            $review = Review::find($reviewId);
            if (!$review) {
                $this->error("Review {$reviewId} not found");
                return 1;
            }
            
            $this->info("Testing with review ID: {$reviewId}");
            $triggerEvaluator->handleReviewReceived($review);
            
        } else {
            // Test with the most recent review
            $review = Review::latest()->first();
            
            if (!$review) {
                $this->error('No reviews found. Please create a review first.');
                return 1;
            }
            
            $this->info("Testing with latest review (ID: {$review->id})");
            $triggerEvaluator->handleReviewReceived($review);
        }

        $this->info('Automation test completed. Check the automation_executions table for results.');
        
        return 0;
    }
}