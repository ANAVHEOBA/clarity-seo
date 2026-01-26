<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AutomationWorkflow>
 */
class AutomationWorkflowFactory extends Factory
{
    protected $model = AutomationWorkflow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'is_active' => true,
            'priority' => $this->faker->numberBetween(0, 10),
            'trigger_type' => $this->faker->randomElement([
                AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
                AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
                AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
                AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            ]),
            'trigger_config' => [
                'rating_threshold' => $this->faker->numberBetween(1, 5),
                'platforms' => $this->faker->randomElements(['google', 'facebook', 'yelp'], 2),
            ],
            'conditions' => [],
            'actions' => [
                [
                    'type' => AutomationWorkflow::ACTION_ADD_TAG,
                    'config' => [
                        'tags' => ['automated', 'test'],
                    ],
                    'critical' => false,
                ],
            ],
            'execution_count' => 0,
            'ai_enabled' => $this->faker->boolean(),
            'ai_config' => [
                'safety_level' => $this->faker->randomElement(['low', 'medium', 'high']),
                'require_approval' => $this->faker->boolean(),
                'default_tone' => $this->faker->randomElement(['professional', 'friendly', 'apologetic']),
            ],
        ];
    }

    public function negativeReviewTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
            'trigger_config' => [
                'rating_threshold' => 3,
                'platforms' => ['google', 'facebook'],
            ],
        ]);
    }

    public function positiveReviewTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
            'trigger_config' => [
                'rating_threshold' => 4,
                'platforms' => ['google', 'facebook'],
            ],
        ]);
    }

    public function sentimentTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
            'trigger_config' => [
                'sentiment_threshold' => 0.3,
            ],
        ]);
    }

    public function aiEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_enabled' => true,
            'ai_config' => [
                'safety_level' => 'medium',
                'require_approval' => true,
                'auto_approval' => false,
                'default_tone' => 'professional',
                'max_length' => 300,
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
            ],
        ]);
    }

    public function withNotification(): static
    {
        return $this->state(fn (array $attributes) => [
            'actions' => array_merge($attributes['actions'] ?? [], [
                [
                    'type' => AutomationWorkflow::ACTION_NOTIFICATION,
                    'config' => [
                        'type' => 'email',
                        'recipients' => [
                            ['type' => 'tenant_admins'],
                        ],
                        'subject' => 'Test Notification',
                        'message' => 'This is a test notification from automation.',
                        'priority' => 'normal',
                    ],
                    'critical' => false,
                ],
            ]),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}