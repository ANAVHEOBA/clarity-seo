<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\User;
use App\Services\Automation\Actions\Contracts\ActionInterface;

class AssignUserAction implements ActionInterface
{
    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $reviewId = $contextData['review_id'] ?? null;
        
        // Handle nested config structure
        $config = $actionConfig['config'] ?? $actionConfig;
        $userId = $config['user_id'] ?? null;

        if (!$reviewId) {
            throw new \InvalidArgumentException('Review ID is required for assign user action');
        }

        if (!$userId) {
            throw new \InvalidArgumentException('User ID is required for assign user action');
        }

        $review = Review::find($reviewId);
        if (!$review) {
            throw new \RuntimeException("Review not found: {$reviewId}");
        }

        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("User not found: {$userId}");
        }

        // Create or update response assignment
        $response = $review->response()->firstOrCreate(
            ['review_id' => $reviewId],
            [
                'user_id' => $userId,
                'status' => 'draft',
                'content' => '',
                'ai_generated' => false,
            ]
        );

        if ($response->user_id !== $userId) {
            $response->update(['user_id' => $userId]);
        }

        return [
            'success' => true,
            'review_id' => $reviewId,
            'assigned_user_id' => $userId,
            'assigned_user_name' => $user->name,
            'response_id' => $response->id,
        ];
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        if (empty($actionConfig['user_id'])) {
            $errors[] = 'user_id is required';
        } elseif (!User::find($actionConfig['user_id'])) {
            $errors[] = 'user_id must reference an existing user';
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'Assign User';
    }

    public function getDescription(): string
    {
        return 'Assign a review to a specific user for response';
    }

    public function getConfigSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'ID of the user to assign the review to',
            ],
        ];
    }
}