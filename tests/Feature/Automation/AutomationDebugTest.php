<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationDebugTest extends TestCase
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

    public function test_debug_automation_trigger(): void
    {
        // Create a simple workflow
        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Debug Test Workflow',
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

        // Check workflow exists
        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'tenant_id' => $this->tenant->id,
        ]);

        // Create a negative review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 2,
            'content' => 'Poor service, very disappointed.',
            'author_name' => 'John Doe',
        ]);

        // Check review was created
        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'location_id' => $this->location->id,
            'rating' => 2,
        ]);

        // Wait a moment for any async processing
        sleep(1);

        // Check if automation execution was created
        $executions = $workflow->executions()->get();
        $this->assertGreaterThan(0, $executions->count(), 'No automation executions were created');

        // Check if review response was created
        $response = $review->response;
        $this->assertNotNull($response, 'No review response was created');

        if ($response) {
            // Since AI service is mocked/failing, response should be created as draft for manual review
            $this->assertEquals('draft', $response->status, 'Response should be in draft status');
            // AI generation may fail in test environment, so we just check that a response was created
            $this->assertNotNull($response->rejection_reason, 'Response should have rejection reason when AI fails');
        }
    }

    public function test_debug_manual_execution(): void
    {
        $this->actingAs($this->user);

        $workflow = AutomationWorkflow::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'trigger_type' => AutomationWorkflow::TRIGGER_MANUAL,
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['test_tag'],
                    ],
                ],
            ],
        ]);

        // Create a review to work with
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'platform' => 'google',
            'rating' => 4,
        ]);

        $contextData = [
            'review_id' => $review->id,
            'location_id' => $this->location->id,
        ];

        $response = $this->postJson(
            "/api/v1/tenants/{$this->tenant->id}/automation/workflows/{$workflow->id}/execute",
            ['context_data' => $contextData]
        );

        $response->assertStatus(200);

        // Check execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
            'trigger_source' => "manual:{$this->user->id}",
        ]);
    }
}