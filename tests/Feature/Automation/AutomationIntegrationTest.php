<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\ReviewSentiment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\AutomationService;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'owner']);

        $this->location = Location::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Mock OpenRouter API responses
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Thank you for your review! We appreciate your feedback.',
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_can_create_automation_workflow(): void
    {
        $this->actingAs($this->user);

        $workflowData = [
            'name' => 'Test AI Response Workflow',
            'description' => 'Automatically respond to negative reviews',
            'is_active' => true,
            'priority' => 10,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => [
                'rating_threshold' => 3,
                'platforms' => ['google', 'facebook'],
            ],
            'conditions' => [
                [
                    'field' => 'platform',
                    'operator' => 'in',
                    'value' => ['google', 'facebook'],
                ],
            ],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_AI_RESPONSE,
                    'config' => [
                        'skip_existing' => true,
                        'auto_publish' => false,
                    ],
                    'critical' => false,
                ],
                [
                    'type' => AutomationWorkflow::ACTION_NOTIFICATION,
                    'config' => [
                        'type' => 'email',
                        'recipients' => [
                            ['type' => 'tenant_admins'],
                        ],
                        'subject' => 'Negative Review Alert',
                        'message' => 'A negative review was received.',
                        'priority' => 'high',
                    ],
                    'critical' => false,
                ],
            ],
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'high',
                'require_approval' => true,
                'default_tone' => 'apologetic',
                'max_length' => 300,
            ],
        ];

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/automation/workflows", $workflowData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'description',
                        'is_active',
                        'priority',
                        'trigger',
                        'conditions',
                        'actions',
                        'ai',
                        'stats',
                        'created_by',
                        'created_at',
                        'updated_at',
                    ],
                ]);

        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Test AI Response Workflow',
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'is_active' => true,
        ]);
    }

    public function test_automation_triggers_on_negative_review(): void
    {
        // Create a workflow for negative reviews
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Negative Review Auto Response',
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
            ],
        ]);

        // Create a negative review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 2,
            'content' => 'Poor service, very disappointed.',
            'author_name' => 'John Doe',
        ]);

        // Check that automation execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "review:{$review->id}",
        ]);

        // Check that a review response was created (may be AI generated or manual draft if AI fails)
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'status' => 'draft', // Should be draft due to require_approval or AI failure
        ]);

        // Check execution logs
        $this->assertDatabaseHas('automation_logs', [
            'workflow_id' => $workflow->id,
            'level' => 'info',
        ]);
    }

    public function test_automation_triggers_on_sentiment_analysis(): void
    {
        // Create a workflow for negative sentiment
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Negative Sentiment Alert',
            'trigger_type' => AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            'trigger_config' => ['sentiment_threshold' => 0.3],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['urgent', 'negative_sentiment'],
                    ],
                ],
                [
                    'type' => AutomationWorkflow::ACTION_ASSIGN_USER,
                    'config' => [
                        'user_id' => $this->user->id,
                    ],
                ],
            ],
            'ai_enabled' => false,
        ]);

        // Create a review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 3,
            'content' => 'The food was terrible and the service was awful.',
        ]);

        // Create sentiment analysis with negative sentiment
        $sentiment = ReviewSentiment::factory()->create([
            'review_id' => $review->id,
            'sentiment' => 'negative',
            'sentiment_score' => 0.2,
            'emotions' => ['angry' => 0.8, 'disappointed' => 0.6],
            'topics' => [
                ['topic' => 'food', 'sentiment' => 'negative', 'score' => 0.9],
                ['topic' => 'service', 'sentiment' => 'negative', 'score' => 0.8],
            ],
        ]);

        // Check that automation execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "sentiment:{$sentiment->id}",
            'status' => 'completed',
        ]);

        // Check that tags were added to review metadata
        $review->refresh();
        $this->assertContains('urgent', $review->metadata['tags'] ?? []);
        $this->assertContains('negative_sentiment', $review->metadata['tags'] ?? []);

        // Check that review response was assigned to user
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_manually_execute_workflow(): void
    {
        $this->actingAs($this->user);

        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'trigger_type' => AutomationWorkflow::TRIGGER_MANUAL,
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_NOTIFICATION,
                    'config' => [
                        'type' => 'email',
                        'recipients' => [
                            ['type' => 'workflow_creator'],
                        ],
                        'subject' => 'Manual Test',
                        'message' => 'This is a manual test execution.',
                    ],
                ],
            ],
        ]);

        $contextData = [
            'test_data' => 'manual execution',
            'location_id' => $this->location->id,
        ];

        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenant->id}/automation/workflows/{$workflow->id}/execute",
            ['context_data' => $contextData]
        );

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Workflow execution started',
                    'workflow_id' => $workflow->id,
                ]);

        // Check execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "manual:{$this->user->id}",
        ]);
    }

    public function test_can_toggle_workflow_status(): void
    {
        $this->actingAs($this->user);

        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/automation/workflows/{$workflow->id}/toggle");

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Workflow deactivated',
                    'is_active' => false,
                ]);

        $workflow->refresh();
        $this->assertFalse($workflow->is_active);
    }

    public function test_can_get_automation_stats(): void
    {
        $this->actingAs($this->user);

        // Create some workflows and executions
        $workflow1 = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'is_active' => true,
            'ai_enabled' => true,
        ]);

        $workflow2 = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'is_active' => false,
            'ai_enabled' => false,
        ]);

        // Create some executions
        $workflow1->executions()->create([
            'trigger_data' => ['test' => 'data'],
            'status' => 'completed',
            'actions_completed' => 2,
            'actions_failed' => 0,
        ]);

        $workflow1->executions()->create([
            'trigger_data' => ['test' => 'data'],
            'status' => 'failed',
            'actions_completed' => 1,
            'actions_failed' => 1,
            'error_message' => 'Test error',
        ]);

        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/automation/stats");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'workflows' => [
                        'total',
                        'active',
                        'inactive',
                        'ai_enabled',
                    ],
                    'executions' => [
                        'total',
                        'successful',
                        'failed',
                        'success_rate',
                        'by_status',
                    ],
                    'top_triggers',
                ])
                ->assertJson([
                    'workflows' => [
                        'total' => 2,
                        'active' => 1,
                        'inactive' => 1,
                        'ai_enabled' => 1,
                    ],
                    'executions' => [
                        'total' => 2,
                        'successful' => 1,
                        'failed' => 1,
                        'success_rate' => 50.0,
                    ],
                ]);
    }

    public function test_can_get_available_actions_and_triggers(): void
    {
        $this->actingAs($this->user);

        $actionsResponse = $this->getJson('/api/v1/automation/actions');
        $actionsResponse->assertStatus(200)
                       ->assertJsonStructure([
                           AutomationWorkflow::ACTION_AI_RESPONSE => [
                               'name',
                               'description',
                               'category',
                           ],
                           AutomationWorkflow::ACTION_NOTIFICATION => [
                               'name',
                               'description',
                               'category',
                           ],
                       ]);

        $triggersResponse = $this->getJson('/api/v1/automation/triggers');
        $triggersResponse->assertStatus(200)
                        ->assertJsonStructure([
                            AutomationWorkflow::TRIGGER_REVIEW_RECEIVED => [
                                'name',
                                'description',
                                'category',
                            ],
                            AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW => [
                                'name',
                                'description',
                                'category',
                            ],
                        ]);
    }

    public function test_workflow_conditions_are_evaluated_correctly(): void
    {
        // Create workflow that only triggers for Google reviews
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'conditions' => [
                [
                    'field' => 'platform',
                    'operator' => 'equals',
                    'value' => 'google',
                ],
            ],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['google_review'],
                    ],
                ],
            ],
        ]);

        // Create Google review (should trigger)
        $googleReview = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 4,
        ]);

        // Create Facebook review (should NOT trigger)
        $facebookReview = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'facebook',
            'rating' => 4,
        ]);

        // Check that only Google review triggered the workflow
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "review:{$googleReview->id}",
        ]);

        $this->assertDatabaseMissing('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "review:{$facebookReview->id}",
        ]);

        // Check that tag was added to Google review
        $googleReview->refresh();
        $this->assertContains('google_review', $googleReview->metadata['tags'] ?? []);

        // Check that tag was NOT added to Facebook review
        $facebookReview->refresh();
        $this->assertNotContains('google_review', $facebookReview->metadata['tags'] ?? []);
    }

    public function test_ai_safety_checks_work(): void
    {
        // Mock AI decision response for unsafe content
        Http::fake([
            'openrouter.ai/*/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'should_respond' => false,
                                    'confidence' => 0.3,
                                    'reason' => 'Contains sensitive legal keywords',
                                    'suggested_tone' => 'professional',
                                    'urgency' => 'high',
                                    'complexity' => 'complex',
                                    'risk_factors' => ['legal_content'],
                                ]),
                            ],
                        ],
                    ],
                ], 200),
        ]);

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

        // Create review with potentially sensitive content
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 1,
            'content' => 'This is terrible service, I will sue you for this!',
        ]);

        // Check that execution was created
        $execution = $workflow->executions()->where('trigger_source', "review:{$review->id}")->first();
        $this->assertNotNull($execution);

        // With the new implementation, a draft response should be created even when AI decides not to respond
        // This allows for manual review of sensitive content
        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'status' => 'draft',
            'ai_generated' => false, // Should be false since AI decided not to respond
        ]);
    }

        public function test_workflow_execution_logs_are_created(): void

        {

            $workflow = AutomationWorkflow::factory()->create([

                'tenant_id' => $this->tenant->id,

                'created_by' => $this->user->id,

                'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,

                'actions' => [

                    [

                        'type' => AutomationWorkflow::ACTION_ADD_TAG,

                        'config' => [

                            'tags' => ['test_tag'],

                        ],

                    ],

                ],

            ]);

    

            $review = Review::factory()->create([

                'location_id' => $this->location->id,

                'platform' => 'google',

                'rating' => 4,

            ]);

    

            // Check that various log levels were created

            $this->assertDatabaseHas('automation_logs', [

                'workflow_id' => $workflow->id,

                'level' => 'info',

                'message' => 'Execution started',

            ]);

    

            $this->assertDatabaseHas('automation_logs', [

                'workflow_id' => $workflow->id,

                'level' => 'info',

                'message' => 'Execution completed successfully',

            ]);

        }

    

        public function test_schedule_is_registered(): void

        {

            $this->artisan('schedule:list')

                ->assertSuccessful()

                ->expectsOutputToContain('app:fetch-reviews')

                ->expectsOutputToContain('automation:run')

                ->expectsOutputToContain('app:send-scheduled-reports');

        }

    }

    