<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Services\Automation\Actions\Contracts\ActionInterface;

class AddTagAction implements ActionInterface
{
    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $reviewId = $contextData['review_id'] ?? null;
        
        // Handle nested config structure
        $config = $actionConfig['config'] ?? $actionConfig;
        $tags = $config['tags'] ?? [];

        if (!$reviewId) {
            throw new \InvalidArgumentException('Review ID is required for add tag action');
        }

        if (empty($tags)) {
            throw new \InvalidArgumentException('At least one tag is required');
        }

        $review = Review::find($reviewId);
        if (!$review) {
            throw new \RuntimeException("Review not found: {$reviewId}");
        }

        // Get existing metadata
        $metadata = $review->metadata ?? [];
        $existingTags = $metadata['tags'] ?? [];

        // Add new tags (avoid duplicates)
        $newTags = array_unique(array_merge($existingTags, $tags));
        $metadata['tags'] = $newTags;

        $review->update(['metadata' => $metadata]);

        \Illuminate\Support\Facades\Log::info('AddTagAction executed', [
            'review_id' => $reviewId,
            'existing_tags' => $existingTags,
            'new_tags' => $tags,
            'final_tags' => $newTags,
            'metadata' => $metadata,
        ]);

        return [
            'success' => true,
            'review_id' => $reviewId,
            'tags_added' => array_diff($newTags, $existingTags),
            'all_tags' => $newTags,
        ];
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        if (empty($actionConfig['tags'])) {
            $errors[] = 'tags is required';
        } elseif (!is_array($actionConfig['tags'])) {
            $errors[] = 'tags must be an array';
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'Add Tag';
    }

    public function getDescription(): string
    {
        return 'Add tags to a review for categorization and filtering';
    }

    public function getConfigSchema(): array
    {
        return [
            'tags' => [
                'type' => 'array',
                'required' => true,
                'description' => 'Array of tags to add to the review',
            ],
        ];
    }
}