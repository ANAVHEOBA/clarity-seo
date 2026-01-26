<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Models\Location;
use App\Services\Automation\Actions\Contracts\ActionInterface;

class UpdateListingAction implements ActionInterface
{
    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $locationId = $contextData['location_id'] ?? null;
        $updates = $actionConfig['updates'] ?? [];

        if (!$locationId) {
            throw new \InvalidArgumentException('Location ID is required for update listing action');
        }

        if (empty($updates)) {
            throw new \InvalidArgumentException('Updates are required');
        }

        $location = Location::find($locationId);
        if (!$location) {
            throw new \RuntimeException("Location not found: {$locationId}");
        }

        // Apply updates
        $updatedFields = [];
        foreach ($updates as $field => $value) {
            if ($location->isFillable($field)) {
                $location->$field = $value;
                $updatedFields[] = $field;
            }
        }

        $location->save();

        // TODO: Trigger listing sync to platforms
        // $this->syncToPlatforms($location, $updatedFields);

        return [
            'success' => true,
            'location_id' => $locationId,
            'updated_fields' => $updatedFields,
            'sync_triggered' => false, // Will be true when platform sync is implemented
        ];
    }

    public function validate(array $actionConfig): array
    {
        $errors = [];

        if (empty($actionConfig['updates'])) {
            $errors[] = 'updates is required';
        } elseif (!is_array($actionConfig['updates'])) {
            $errors[] = 'updates must be an array';
        }

        return $errors;
    }

    public function getName(): string
    {
        return 'Update Listing';
    }

    public function getDescription(): string
    {
        return 'Update location listing data and sync to platforms';
    }

    public function getConfigSchema(): array
    {
        return [
            'updates' => [
                'type' => 'object',
                'required' => true,
                'description' => 'Object containing field updates for the location',
            ],
        ];
    }
}