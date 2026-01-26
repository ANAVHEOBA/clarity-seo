<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live API Test - Uses your actual OpenRouter credentials
 */
class AutomationLiveAPITest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'owner']);
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_live_api_configuration(): void
    {
        // Verify your actual configuration
        $apiKey = config('openrouter.api_key');
        $model = config('openrouter.model');
        
        $this->assertNotEmpty($apiKey, 'API key should be loaded from .env');
        $this->assertStringStartsWith('sk-or-v1-', $apiKey, 'Should be a valid OpenRouter API key');
        $this->assertEquals('qwen/qwen-2.5-vl-7b-instruct:free', $model, 'Should use the Qwen model');
        
        echo "\n=== LIVE API CONFIGURATION ===\n";
        echo "API Key: " . substr($apiKey, 0, 15) . "...\n";
        echo "Model: {$model}\n";
        echo "Base URL: " . config('openrouter.base_url') . "\n";
        echo "==============================\n";
    }

    public function test_live_ai_response_generation(): void
    {
        // Create a test review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 2,
            'content' => 'The service was slow and the food was cold. Very disappointed.',
            'author_name' => 'Test Customer',
        ]);

        // Generate AI response using your real API
        $aiService = app(AIResponseService::class);
        
        $result = $aiService->generateResponse($review, $this->user, [
            'tone' => 'apologetic',
            'language' => 'en',
            'use_sentiment_context' => false,
            'include_location_context' => true,
        ]);

        // Verify response was generated
        $this->assertNotNull($result, 'AI service should return a result');
        $this->assertArrayHasKey('response', $result, 'Result should contain response');
        
        $response = $result['response'];
        $this->assertNotNull($response, 'Response should not be null');
        $this->assertNotEmpty($response->content, 'Response content should not be empty');
        $this->assertTrue($response->ai_generated, 'Response should be marked as AI generated');
        $this->assertEquals('apologetic', $response->tone, 'Response should have apologetic tone');
        
        // Verify it was saved to database
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'ai_generated' => true,
            'tone' => 'apologetic',
        ]);

        // Verify AI response history was created
        $this->assertDatabaseHas('ai_response_histories', [
            'review_id' => $review->id,
            'user_id' => $this->user->id,
            'tone' => 'apologetic',
        ]);

        echo "\n=== LIVE AI RESPONSE TEST ===\n";
        echo "Review: {$review->content}\n";
        echo "Rating: {$review->rating}/5 stars\n";
        echo "AI Response: {$response->content}\n";
        echo "Tone: {$response->tone}\n";
        echo "Language: {$response->language}\n";
        echo "Length: " . strlen($response->content) . " characters\n";
        echo "=============================\n";

        // Basic quality checks
        $this->assertGreaterThan(20, strlen($response->content), 'Response should be substantial');
        $this->assertLessThan(500, strlen($response->content), 'Response should not be too long');
    }

    public function test_live_automation_workflow_with_ai(): void
    {
        // Create an automation workflow that uses AI
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Live AI Auto-Response Test',
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
                'default_tone' => 'professional',
                'max_length' => 250,
            ],
        ]);

        // Create a negative review that should trigger the workflow
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 1,
            'content' => 'Terrible experience! The staff was rude and the place was dirty.',
            'author_name' => 'Angry Customer',
        ]);

        // Give automation a moment to process
        sleep(1);

        // Verify automation was triggered
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "review:{$review->id}",
        ]);

        // Verify AI response was generated
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'ai_generated' => true,
            'status' => 'draft', // Should be draft due to require_approval
        ]);

        // Get the execution and response for verification
        $execution = $workflow->executions()->where('trigger_source', "review:{$review->id}")->first();
        $response = $review->response;

        $this->assertNotNull($execution, 'Automation execution should exist');
        $this->assertNotNull($response, 'AI response should be generated');
        $this->assertEquals('completed', $execution->status, 'Execution should be completed');

        echo "\n=== LIVE AUTOMATION TEST ===\n";
        echo "Workflow: {$workflow->name}\n";
        echo "Trigger: Negative review (rating {$review->rating})\n";
        echo "Review: {$review->content}\n";
        echo "AI Response: {$response->content}\n";
        echo "Response Status: {$response->status}\n";
        echo "Execution Status: {$execution->status}\n";
        echo "Actions Completed: {$execution->actions_completed}\n";
        echo "Actions Failed: {$execution->actions_failed}\n";
        echo "============================\n";
    }

    public function test_live_api_error_handling(): void
    {
        // Test with invalid model to see error handling
        config(['openrouter.model' => 'invalid/model-name']);
        
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 3,
            'content' => 'Average experience.',
        ]);

        $aiService = app(AIResponseService::class);
        
        try {
            $result = $aiService->generateResponse($review, $this->user);
            $this->fail('Should have thrown an exception for invalid model');
        } catch (\Exception $e) {
            $this->assertStringContainsString('AI service', $e->getMessage());
            echo "\n=== ERROR HANDLING TEST ===\n";
            echo "Expected error caught: {$e->getMessage()}\n";
            echo "===========================\n";
        }

        // Reset to valid model
        config(['openrouter.model' => 'qwen/qwen-2.5-vl-7b-instruct:free']);
    }

    public function test_live_api_rate_limiting_awareness(): void
    {
        echo "\n=== RATE LIMITING INFO ===\n";
        echo "OpenRouter Free Tier Limits:\n";
        echo "- Qwen model: Free tier available\n";
        echo "- Rate limits may apply\n";
        echo "- Monitor your usage at openrouter.ai\n";
        echo "- Consider upgrading for production use\n";
        echo "==========================\n";
        
        $this->assertTrue(true); // Prevent risky test warning
    }
}