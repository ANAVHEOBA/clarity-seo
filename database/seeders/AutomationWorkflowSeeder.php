<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class AutomationWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        $user = User::first();

        if (!$tenant || !$user) {
            if ($this->command) {
                $this->command->warn('No tenant or user found. Please run the main seeder first.');
            }
            return;
        }

        // 1. AI Auto-Response for Negative Reviews
        AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'AI Auto-Response for Negative Reviews',
            'description' => 'Automatically generate AI responses for reviews with 3 stars or below',
            'is_active' => true,
            'priority' => 10,
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => [
                'rating_threshold' => 3,
                'platforms' => ['google', 'facebook', 'yelp'],
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
                        'auto_publish' => false, // Require manual approval
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
                        'subject' => 'Negative Review Alert: {{location.name}}',
                        'message' => 'A negative review ({{review.rating}} stars) was received for {{location.name}} on {{review.platform}}. An AI response has been generated and is awaiting approval.',
                        'priority' => 'high',
                    ],
                    'critical' => false,
                ],
            ],
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'high',
                'require_approval' => true,
                'auto_approval' => false,
                'default_tone' => 'apologetic',
                'max_length' => 300,
            ],
        ]);

        // 2. Thank You for Positive Reviews
        AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Thank You for Positive Reviews',
            'description' => 'Automatically thank customers for 4-5 star reviews',
            'is_active' => true,
            'priority' => 5,
            'trigger_type' => AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
            'trigger_config' => [
                'rating_threshold' => 4,
            ],
            'conditions' => [],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_AI_RESPONSE,
                    'config' => [
                        'skip_existing' => true,
                        'auto_publish' => true, // Auto-publish positive responses
                    ],
                    'critical' => false,
                ],
            ],
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'medium',
                'require_approval' => false,
                'auto_approval' => true,
                'auto_approval_confidence' => 0.7,
                'auto_approval_max_rating' => 5,
                'default_tone' => 'friendly',
                'max_length' => 200,
            ],
        ]);

        // 3. Sentiment Alert for Very Negative Reviews
        AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Critical Sentiment Alert',
            'description' => 'Alert management when very negative sentiment is detected',
            'is_active' => true,
            'priority' => 15,
            'trigger_type' => AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            'trigger_config' => [
                'sentiment_threshold' => 0.2, // Very negative
            ],
            'conditions' => [],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['urgent', 'negative_sentiment', 'requires_attention'],
                    ],
                    'critical' => false,
                ],
                [
                    'type' => AutomationWorkflow::ACTION_NOTIFICATION,
                    'config' => [
                        'type' => 'email',
                        'recipients' => [
                            ['type' => 'workflow_creator'],
                            ['type' => 'tenant_admins'],
                        ],
                        'subject' => 'URGENT: Very Negative Sentiment Detected',
                        'message' => 'A review with very negative sentiment (score: {{sentiment_score}}) was detected for {{location.name}}. Immediate attention may be required.\n\nReview: "{{review.content}}"',
                        'priority' => 'high',
                    ],
                    'critical' => false,
                ],
                [
                    'type' => AutomationWorkflow::ACTION_ASSIGN_USER,
                    'config' => [
                        'user_id' => $user->id,
                    ],
                    'critical' => false,
                ],
            ],
            'ai_enabled' => false,
        ]);

        // 4. Weekly Review Summary Report
        AutomationWorkflow::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => 'Weekly Review Summary',
            'description' => 'Generate and email weekly review summary reports',
            'is_active' => true,
            'priority' => 1,
            'trigger_type' => AutomationWorkflow::TRIGGER_SCHEDULED,
            'trigger_config' => [
                'schedule' => 'weekly',
                'day_of_week' => 'monday',
                'time' => '09:00',
            ],
            'conditions' => [],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_GENERATE_REPORT,
                    'config' => [
                        'report_type' => 'summary',
                        'format' => 'pdf',
                        'date_from' => '-7 days',
                        'date_to' => 'today',
                        'email_recipients' => [
                            $user->email,
                        ],
                    ],
                    'critical' => false,
                ],
            ],
            'ai_enabled' => false,
        ]);

        if ($this->command) {
            $this->command->info('Created 4 sample automation workflows');
        }
    }
}