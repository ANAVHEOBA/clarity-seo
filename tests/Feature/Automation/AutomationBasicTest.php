<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationBasicTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_automation_workflow_model(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $workflow = AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Test Workflow',
            'description' => 'A test workflow',
            'is_active' => true,
            'priority' => 5,
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => ['test' => 'config'],
            'conditions' => [],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => ['tags' => ['test']],
                ],
            ],
            'ai_enabled' => false,
            'ai_config' => [],
        ]);

        $this->assertDatabaseHas('automation_workflows', [
            'id' => $workflow->id,
            'name' => 'Test Workflow',
            'tenant_id' => $tenant->id,
        ]);

        // Test relationships
        $this->assertEquals($tenant->id, $workflow->tenant->id);
        $this->assertEquals($user->id, $workflow->createdBy->id);
    }

    public function test_workflow_trigger_matching(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $workflow = AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Negative Review Workflow',
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => ['rating_threshold' => 3],
            'conditions' => [],
            'actions' => [['type' => AutomationWorkflow::ACTION_ADD_TAG, 'config' => ['tags' => ['negative']]]],
            'ai_enabled' => false,
        ]);

        // Test trigger matching
        $this->assertTrue($workflow->matchesTrigger(
            AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            ['rating' => 2]
        ));

        $this->assertFalse($workflow->matchesTrigger(
            AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            ['rating' => 4]
        ));

        $this->assertFalse($workflow->matchesTrigger(
            AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
            ['rating' => 2]
        ));
    }

    public function test_workflow_condition_evaluation(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $workflow = AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Google Only Workflow',
            'trigger_type' => AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
            'trigger_config' => [],
            'conditions' => [
                [
                    'field' => 'platform',
                    'operator' => 'equals',
                    'value' => 'google',
                ],
            ],
            'actions' => [['type' => AutomationWorkflow::ACTION_ADD_TAG, 'config' => ['tags' => ['google']]]],
            'ai_enabled' => false,
        ]);

        // Test condition matching
        $this->assertTrue($workflow->matchesConditions(['platform' => 'google']));
        $this->assertFalse($workflow->matchesConditions(['platform' => 'facebook']));
    }
}