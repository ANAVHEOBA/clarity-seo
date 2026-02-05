<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Models\Review;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AutomationTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'owner']);
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_review_received_trigger_fires_on_new_review(): void
    {
        // Create a workflow that triggers on any review
        $workflow = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Test Review Received',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => [],
            'actions' => [
                [
                    'type' => 'notification',
                    'recipients' => [['type' => 'workflow_creator']],
                    'subject' => 'New Review Received',
                    'message' => 'A new review was received: {{review.content}}',
                ],
            ],
        ]);

        // Create a review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 4,
            'content' => 'Great service!',
        ]);

        // Manually trigger (simulating what happens in ReviewService)
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Check that execution was created
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
        ]);

        // Check that workflow execution count increased
        // Note: handleReviewReceived triggers both REVIEW_RECEIVED and POSITIVE/NEGATIVE_REVIEW
        // So for a rating of 4, it will trigger REVIEW_RECEIVED once and POSITIVE_REVIEW once
        $workflow->refresh();
        $this->assertGreaterThanOrEqual(1, $workflow->execution_count);
    }

    public function test_negative_review_trigger_fires_for_low_ratings(): void
    {
        // Create a workflow for negative reviews
        $workflow = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Negative Review Alert',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 3],
            'actions' => [
                [
                    'type' => 'notification',
                    'recipients' => [['type' => 'tenant_admins']],
                    'subject' => 'Negative Review Alert',
                    'message' => 'Negative review from {{review.author}}: {{review.content}}',
                ],
            ],
        ]);

        // Create a negative review (rating <= 3)
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 2,
            'content' => 'Poor experience',
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Should trigger negative review workflow
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
        ]);
    }

    public function test_negative_review_trigger_does_not_fire_for_positive_ratings(): void
    {
        // Create a workflow for negative reviews
        $workflow = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Negative Review Alert',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 3],
            'actions' => [
                [
                    'type' => 'notification',
                    'recipients' => [['type' => 'tenant_admins']],
                    'subject' => 'Negative Review Alert',
                    'message' => 'Negative review detected',
                ],
            ],
        ]);

        // Create a positive review (rating > 3)
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 5,
            'content' => 'Excellent!',
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Should NOT trigger negative review workflow
        $this->assertDatabaseMissing('automation_executions', [
            'workflow_id' => $workflow->id,
        ]);
    }

    public function test_positive_review_trigger_fires_for_high_ratings(): void
    {
        // Create a workflow for positive reviews
        $workflow = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Positive Review Thank You',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 4],
            'actions' => [
                [
                    'type' => 'ai_response',
                    'tone' => 'grateful',
                    'auto_publish' => false,
                ],
            ],
        ]);

        // Create a positive review (rating >= 4)
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 5,
            'content' => 'Amazing service!',
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Should trigger positive review workflow
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow->id,
        ]);
    }

    public function test_inactive_workflows_do_not_trigger(): void
    {
        // Create an inactive workflow
        $workflow = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Inactive Workflow',
            'is_active' => false, // Inactive
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => [],
            'actions' => [
                [
                    'type' => 'notification',
                    'recipients' => [['type' => 'workflow_creator']],
                    'subject' => 'Test',
                    'message' => 'Test',
                ],
            ],
        ]);

        // Create a review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 4,
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Should NOT trigger inactive workflow
        $this->assertDatabaseMissing('automation_executions', [
            'workflow_id' => $workflow->id,
        ]);
    }

    public function test_multiple_workflows_can_trigger_for_same_review(): void
    {
        // Create two workflows that should both trigger
        $workflow1 = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'General Review Handler',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => [],
            'actions' => [['type' => 'notification', 'recipients' => [], 'message' => 'Test']],
        ]);

        $workflow2 = AutomationWorkflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'Negative Review Handler',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 3],
            'actions' => [['type' => 'notification', 'recipients' => [], 'message' => 'Test']],
        ]);

        // Create a negative review
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 2,
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Both workflows should have triggered
        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow1->id,
        ]);

        $this->assertDatabaseHas('automation_executions', [
            'workflow_id' => $workflow2->id,
        ]);
    }

    public function test_workflows_only_trigger_for_their_tenant(): void
    {
        // Create another tenant with a workflow
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create();
        $otherTenant->users()->attach($otherUser, ['role' => 'owner']);

        $otherWorkflow = AutomationWorkflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $otherUser->id,
            'name' => 'Other Tenant Workflow',
            'is_active' => true,
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => [],
            'actions' => [['type' => 'notification', 'recipients' => [], 'message' => 'Test']],
        ]);

        // Create a review for our tenant
        $review = Review::factory()->create([
            'location_id' => $this->location->id,
            'rating' => 4,
        ]);

        // Trigger
        $triggerEvaluator = app(TriggerEvaluator::class);
        $triggerEvaluator->handleReviewReceived($review);

        // Other tenant's workflow should NOT trigger
        $this->assertDatabaseMissing('automation_executions', [
            'workflow_id' => $otherWorkflow->id,
        ]);
    }
}
