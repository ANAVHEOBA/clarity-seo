<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\User;
use App\Services\Automation\Actions\Contracts\ActionInterface;
use App\Services\Automation\AIAutomationService;
use Illuminate\Support\Facades\Log;

class AIResponseAction implements ActionInterface
{
    public function __construct(
        protected AIAutomationService $aiAutomationService
    ) {}

    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $reviewId = $contextData['review_id'] ?? null;
        
        // Handle nested config structure
        $config = $actionConfig['config'] ?? $actionConfig;
        $userId = $config['user_id'] ?? $contextData['user_id'] ?? $workflow->created_by;

        if (!$reviewId) {
            throw new \InvalidArgumentException('Review ID is required for AI response action');
        }

        $review = Review::find($reviewId);
        if (!$review) {
            throw new \RuntimeException("Review not found: {$reviewId}");
        }

        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        // Check if response already exists and skip_existing is true
        if (($config['skip_existing'] ?? true) && $review->hasResponse()) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'Response already exists',
                'review_id' => $reviewId,
            ];
        }

        Log::info('Executing AI response action', [
            'review_id' => $reviewId,
            'user_id' => $userId,
            'workflow_id' => $workflow->id,
        ]);

        try {
            // Use intelligent response generation
            $result = $this->aiAutomationService->generateIntelligentResponse($review, $user, $workflow);

            if (!$result['success']) {
                // If AI generation fails, create a draft response for manual handling
                $response = $review->response()->create([
                    'user_id' => $userId,
                    'content' => '',
                    'status' => 'draft',
                    'ai_generated' => false,
                    'rejection_reason' => 'AI generation failed: ' . $result['reason'],
                ]);

                return [
                    'success' => true,
                    'response_id' => $response->id,
                    'ai_failed' => true,
                    'reason' => $result['reason'],
                    'status' => 'draft',
                    'requires_manual_review' => true,
                ];
            }

            $response = $result['response'];
            $autoApproved = $result['auto_approved'] ?? false;

            // Auto-publish if configured and approved
            if ($autoApproved && ($config['auto_publish'] ?? false)) {
                $response->update(['status' => 'published']);
                
                // TODO: Integrate with platform publishing services
                // $this->publishToPlatform($response);
            }

            return [
                'success' => true,
                'response_id' => $response->id,
                'content' => $response->content,
                'status' => $response->status,
                'auto_approved' => $autoApproved,
                'ai_decision' => $result['decision'] ?? [],
                'safety_check' => $result['safety_check'] ?? [],
                'tone' => $response->tone,
                'language' => $response->language,
            ];

        } catch (\Exception $e) {
            Log::error('AI response action failed', [
                'review_id' => $reviewId,
                'user_id' => $userId,
                'workflow_id' => $workflow->id,
                'error' => $e->getMessage(),
            ]);

            // Create a draft response for manual handling even if AI fails
            $response = $review->response()->create([
                'user_id' => $userId,
                'content' => '',
                'status' => 'draft',
                'ai_generated' => false,
                'rejection_reason' => 'AI action failed: ' . $e->getMessage(),
            ]);

            return [
                'success' => true,
                'response_id' => $response->id,
                'ai_failed' => true,
                'error' => $e->getMessage(),
                'status' => 'draft',
                'requires_manual_review' => true,
            ];
        }
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        // Validate user_id if provided
        if (isset($actionConfig['user_id'])) {
            if (!is_numeric($actionConfig['user_id'])) {
                $errors[] = 'user_id must be a valid user ID';
            } elseif (!User::find($actionConfig['user_id'])) {
                $errors[] = 'user_id must reference an existing user';
            }
        }

        // Validate boolean flags
        foreach (['skip_existing', 'auto_publish'] as $flag) {
            if (isset($actionConfig[$flag]) && !is_bool($actionConfig[$flag])) {
                $errors[] = "{$flag} must be a boolean value";
            }
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'AI Response';
    }

    public function getDescription(): string
    {
        return 'Generate an AI-powered response to a review with intelligent decision making and safety checks';
    }

    public function getConfigSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'User ID to attribute the response to (defaults to workflow creator)',
            ],
            'skip_existing' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'description' => 'Skip if response already exists',
            ],
            'auto_publish' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Automatically publish approved responses',
            ],
        ];
    }
}