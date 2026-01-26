<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real API Integration Test - Uses actual OpenRouter API calls
 * 
 * This test requires:
 * - OPENROUTER_API_KEY to be set in .env
 * - OPENROUTER_MODEL to be set in .env
 * - Internet connection
 * - Valid OpenRouter API credits
 */
class AutomationRealAPITest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip if no API key is configured
        if (empty(config('openrouter.api_key'))) {
            $this->markTestSkipped('OpenRouter API key not configured. Set OPENROUTER_API_KEY in .env to run real API tests.');
        }

        // Create test data
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'owner']);

        $this->location = Location::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // No HTTP mocking - use real API calls
    }

    public function test_real_ai_response_generation_for_negative_review(): void
    {
        $this->markTestSkipped('Skipping real API test to avoid charges. Remove this line to run with real API.');

        // Create a workflow for negative reviews with AI enabled
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Real AI Response Test',
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 3],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_AI_RESPONSE,
                    'config' => [
                        'skip_existing' => true,
                        'auto_publish' => false,
                    ],
                ],
            ],
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'medium',
                'require_approval' => true,
                'default_tone' => 'apologetic',
                'max_length' => 200,
            ],
        ]);

        // Create a negative review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 2,
            'content' => 'The service was slow and the food was cold. Very disappointed with my experience.',
            'author_name' => 'John Doe',
        ]);

        // Wait a moment for automation to process
        sleep(2);

        // Check that automation execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "review:{$review->id}",
        ]);

        // Check that a real AI response was created
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'ai_generated' => true,
            'status' => 'draft',
        ]);

        // Get the response and verify it's not empty and contextual
        $response = $review->response;
        $this->assertNotNull($response);
        $this->assertNotEmpty($response->content);
        $this->assertGreaterThan(20, strlen($response->content)); // Should be substantial
        
        // Check that response is contextual (mentions apology for negative review)
        $content = strtolower($response->content);
        $this->assertTrue(
            str_contains($content, 'sorry') || 
            str_contains($content, 'apologize') || 
            str_contains($content, 'regret'),
            'AI response should contain an apology for negative review'
        );

        // Log the actual response for manual verification
        $this->addToAssertionCount(1); // Prevent risky test warning
        echo "\n=== REAL AI RESPONSE ===\n";
        echo "Review: {$review->content}\n";
        echo "AI Response: {$response->content}\n";
        echo "Tone: {$response->tone}\n";
        echo "Language: {$response->language}\n";
        echo "========================\n";
    }

    public function test_real_ai_decision_making_for_sensitive_content(): void
    {
        $this->markTestSkipped('Skipping real API test to avoid charges. Remove this line to run with real API.');

        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_AI_RESPONSE,
                    'config' => [
                        'skip_existing' => true,
                    ],
                ],
            ],
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'high',
                'require_approval' => true,
            ],
        ]);

        // Create review with potentially sensitive legal content
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 1,
            'content' => 'This is terrible service, I will sue you and take legal action for this negligence!',
        ]);

        sleep(2);

        // Check execution was created
        $execution = $workflow->executions()->where('trigger_source', "review:{$review->id}")->first();
        $this->assertNotNull($execution);

        // The AI should detect this as risky and either:
        // 1. Not create a response, or
        // 2. Create a response but mark it for manual review
        
        $response = $review->response;
        if ($response) {
            // If response was created, it should be marked as draft due to safety concerns
            $this->assertEquals('draft', $response->status);
            echo "\n=== AI SAFETY TEST ===\n";
            echo "Sensitive Review: {$review->content}\n";
            echo "AI Response: {$response->content}\n";
            echo "Status: {$response->status}\n";
            echo "======================\n";
        } else {
            // AI correctly decided not to respond to sensitive content
            echo "\n=== AI SAFETY TEST ===\n";
            echo "Sensitive Review: {$review->content}\n";
            echo "AI Decision: No response generated (safety check)\n";
            echo "======================\n";
        }

        $this->addToAssertionCount(1);
    }

    public function test_real_sentiment_analysis_integration(): void
    {
        $this->markTestSkipped('Skipping real API test to avoid charges. Remove this line to run with real API.');

        // Create workflow that triggers on negative sentiment
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'trigger_type' => AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            'trigger_config' => ['sentiment_threshold' => 0.3],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['negative_sentiment_detected'],
                    ],
                ],
            ],
        ]);

        // Create a review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 3,
            'content' => 'The food was absolutely terrible and the service was horrible. I felt angry and disappointed.',
        ]);

        // Manually trigger sentiment analysis (in real app this would be automatic)
        $sentimentService = app(\App\Services\Sentiment\SentimentService::class);
        $sentiment = $sentimentService->analyzeReview($review);

        $this->assertNotNull($sentiment);
        
        // Check that automation was triggered based on real sentiment analysis
        if ($sentiment->sentiment_score <= 0.3) {
            $this->assertDatabaseHas('automation_executions', [
                'workflow_id' => $workflow->id,
                'trigger_source' => "sentiment:{$sentiment->id}",
            ]);

            // Check that tag was added
            $review->refresh();
            $this->assertContains('negative_sentiment_detected', $review->metadata['tags'] ?? []);
        }

        echo "\n=== REAL SENTIMENT ANALYSIS ===\n";
        echo "Review: {$review->content}\n";
        echo "Sentiment: {$sentiment->sentiment}\n";
        echo "Score: {$sentiment->sentiment_score}\n";
        echo "Emotions: " . json_encode($sentiment->emotions) . "\n";
        echo "Topics: " . json_encode($sentiment->topics) . "\n";
        echo "===============================\n";

        $this->addToAssertionCount(1);
    }

    public function test_api_configuration_is_loaded(): void
    {
        // Test that configuration is properly loaded from .env
        $this->assertNotEmpty(config('openrouter.api_key'), 'OPENROUTER_API_KEY should be set in .env');
        $this->assertNotEmpty(config('openrouter.model'), 'OPENROUTER_MODEL should be set in .env');
        $this->assertEquals('https://openrouter.ai/api/v1', config('openrouter.base_url'));
        
        // Verify the model format
        $model = config('openrouter.model');
        $this->assertStringContainsString('/', $model, 'Model should be in format provider/model-name');
        
        echo "\n=== API CONFIGURATION ===\n";
        echo "API Key: " . substr(config('openrouter.api_key'), 0, 10) . "...\n";
        echo "Model: " . config('openrouter.model') . "\n";
        echo "Base URL: " . config('openrouter.base_url') . "\n";
        echo "=========================\n";
    }

    public function test_api_connectivity(): void
    {
        $this->markTestSkipped('Skipping real API test to avoid charges. Remove this line to run with real API.');

        // Test basic API connectivity
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . config('openrouter.api_key'),
            'Content-Type' => 'application/json',
        ])->timeout(10)->post(config('openrouter.base_url') . '/chat/completions', [
            'model' => config('openrouter.model'),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Say "API test successful" if you can read this.',
                ],
            ],
            'max_tokens' => 10,
        ]);

        $this->assertTrue($response->successful(), 'API should be reachable');
        
        $content = $response->json('choices.0.message.content');
        $this->assertNotEmpty($content, 'API should return content');
        
        echo "\n=== API CONNECTIVITY TEST ===\n";
        echo "Status: " . $response->status() . "\n";
        echo "Response: " . $content . "\n";
        echo "=============================\n";
    }
}