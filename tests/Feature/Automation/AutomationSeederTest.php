<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AutomationWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_workflow_seeder_creates_workflows(): void
    {
        // Create required test data
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Run the seeder
        $seeder = new AutomationWorkflowSeeder();
        $seeder->run();

        // Check that workflows were created
        $this->assertDatabaseCount('automation_workflows', 4);

        // Check specific workflows
        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $tenant->id,
            'name' => 'AI Auto-Response for Negative Reviews',
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'is_active' => true,
            'ai_enabled' => true,
        ]);

        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $tenant->id,
            'name' => 'Thank You for Positive Reviews',
            'trigger_type' => AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
            'is_active' => true,
            'ai_enabled' => true,
        ]);

        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $tenant->id,
            'name' => 'Critical Sentiment Alert',
            'trigger_type' => AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            'is_active' => true,
            'ai_enabled' => false,
        ]);

        $this->assertDatabaseHas('automation_workflows', [
            'tenant_id' => $tenant->id,
            'name' => 'Weekly Review Summary',
            'trigger_type' => AutomationWorkflow::TRIGGER_SCHEDULED,
            'is_active' => true,
            'ai_enabled' => false,
        ]);

        // Verify workflow configurations
        $negativeReviewWorkflow = AutomationWorkflow::where('name', 'AI Auto-Response for Negative Reviews')->first();
        $this->assertNotNull($negativeReviewWorkflow);
        $this->assertEquals(3, $negativeReviewWorkflow->trigger_config['rating_threshold']);
        $this->assertCount(2, $negativeReviewWorkflow->actions);
        $this->assertEquals('high', $negativeReviewWorkflow->ai_config['safety_level']);
        $this->assertTrue($negativeReviewWorkflow->ai_config['require_approval']);

        $positiveReviewWorkflow = AutomationWorkflow::where('name', 'Thank You for Positive Reviews')->first();
        $this->assertNotNull($positiveReviewWorkflow);
        $this->assertEquals(4, $positiveReviewWorkflow->trigger_config['rating_threshold']);
        $this->assertCount(1, $positiveReviewWorkflow->actions);
        $this->assertEquals('medium', $positiveReviewWorkflow->ai_config['safety_level']);
        $this->assertTrue($positiveReviewWorkflow->ai_config['auto_approval']);

        $sentimentWorkflow = AutomationWorkflow::where('name', 'Critical Sentiment Alert')->first();
        $this->assertNotNull($sentimentWorkflow);
        $this->assertEquals(0.2, $sentimentWorkflow->trigger_config['sentiment_threshold']);
        $this->assertCount(3, $sentimentWorkflow->actions);
        $this->assertFalse($sentimentWorkflow->ai_enabled);

        $reportWorkflow = AutomationWorkflow::where('name', 'Weekly Review Summary')->first();
        $this->assertNotNull($reportWorkflow);
        $this->assertEquals('weekly', $reportWorkflow->trigger_config['schedule']);
        $this->assertCount(1, $reportWorkflow->actions);
        $this->assertEquals(AutomationWorkflow::ACTION_GENERATE_REPORT, $reportWorkflow->actions[0]['type']);
    }

    public function test_seeder_handles_missing_tenant_gracefully(): void
    {
        // Don't create any tenant or user
        
        $seeder = new AutomationWorkflowSeeder();
        
        // Should not throw exception
        $seeder->run();
        
        // Should not create any workflows
        $this->assertDatabaseCount('automation_workflows', 0);
    }

    public function test_seeder_creates_valid_workflow_configurations(): void
    {
        // Create required test data
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user, ['role' => 'owner']);

        // Run the seeder
        $seeder = new AutomationWorkflowSeeder();
        $seeder->run();

        // Get all created workflows
        $workflows = AutomationWorkflow::all();

        foreach ($workflows as $workflow) {
            // Validate basic structure
            $this->assertNotEmpty($workflow->name);
            $this->assertNotEmpty($workflow->trigger_type);
            $this->assertIsArray($workflow->actions);
            $this->assertNotEmpty($workflow->actions);

            // Validate trigger types are valid
            $validTriggers = [
                AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
                AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
                AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
                AutomationWorkflow::TRIGGER_SCHEDULED,
            ];
            $this->assertContains($workflow->trigger_type, $validTriggers);

            // Validate action types are valid
            $validActions = [
                AutomationWorkflow::ACTION_AI_RESPONSE,
                AutomationWorkflow::ACTION_NOTIFICATION,
                AutomationWorkflow::ACTION_ADD_TAG,
                AutomationWorkflow::ACTION_ASSIGN_USER,
                AutomationWorkflow::ACTION_GENERATE_REPORT,
            ];

            foreach ($workflow->actions as $action) {
                $this->assertArrayHasKey('type', $action);
                $this->assertContains($action['type'], $validActions);
                $this->assertArrayHasKey('config', $action);
                $this->assertIsArray($action['config']);
            }

            // Validate AI config if AI is enabled
            if ($workflow->ai_enabled) {
                $this->assertIsArray($workflow->ai_config);
                $this->assertArrayHasKey('safety_level', $workflow->ai_config);
                $this->assertContains($workflow->ai_config['safety_level'], ['low', 'medium', 'high']);
                
                if (isset($workflow->ai_config['default_tone'])) {
                    $this->assertContains($workflow->ai_config['default_tone'], ['professional', 'friendly', 'apologetic', 'empathetic']);
                }
            }

            // Validate trigger config
            $this->assertIsArray($workflow->trigger_config);
            
            if ($workflow->trigger_type === AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW || 
                $workflow->trigger_type === AutomationWorkflow::TRIGGER_POSITIVE_REVIEW) {
                $this->assertArrayHasKey('rating_threshold', $workflow->trigger_config);
                $this->assertIsInt($workflow->trigger_config['rating_threshold']);
                $this->assertGreaterThanOrEqual(1, $workflow->trigger_config['rating_threshold']);
                $this->assertLessThanOrEqual(5, $workflow->trigger_config['rating_threshold']);
            }

            if ($workflow->trigger_type === AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE) {
                $this->assertArrayHasKey('sentiment_threshold', $workflow->trigger_config);
                $this->assertIsFloat($workflow->trigger_config['sentiment_threshold']);
                $this->assertGreaterThanOrEqual(0, $workflow->trigger_config['sentiment_threshold']);
                $this->assertLessThanOrEqual(1, $workflow->trigger_config['sentiment_threshold']);
            }
        }
    }
}