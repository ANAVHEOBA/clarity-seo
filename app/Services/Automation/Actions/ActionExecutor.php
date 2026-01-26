<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Models\AutomationWorkflow;
use App\Services\Automation\Actions\Contracts\ActionInterface;
use Illuminate\Support\Facades\Log;

class ActionExecutor
{
    protected array $actions = [];

    public function __construct()
    {
        $this->registerActions();
    }

    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array
    {
        $actionType = $actionConfig['type'] ?? null;

        if (!$actionType) {
            throw new \InvalidArgumentException('Action type is required');
        }

        if (!isset($this->actions[$actionType])) {
            throw new \InvalidArgumentException("Unknown action type: {$actionType}");
        }

        $actionClass = $this->actions[$actionType];
        $action = app($actionClass);

        if (!$action instanceof ActionInterface) {
            throw new \RuntimeException("Action {$actionType} must implement ActionInterface");
        }

        Log::info("Executing automation action", [
            'action_type' => $actionType,
            'workflow_id' => $workflow->id,
            'context_keys' => array_keys($contextData),
        ]);

        return $action->execute($actionConfig, $contextData, $workflow);
    }

    public function getAvailableActions(): array
    {
        return array_keys($this->actions);
    }

    protected function registerActions(): void
    {
        $this->actions = [
            AutomationWorkflow::ACTION_AI_RESPONSE => AIResponseAction::class,
            AutomationWorkflow::ACTION_NOTIFICATION => NotificationAction::class,
            AutomationWorkflow::ACTION_ASSIGN_USER => AssignUserAction::class,
            AutomationWorkflow::ACTION_ADD_TAG => AddTagAction::class,
            AutomationWorkflow::ACTION_UPDATE_LISTING => UpdateListingAction::class,
            AutomationWorkflow::ACTION_GENERATE_REPORT => GenerateReportAction::class,
        ];
    }
}