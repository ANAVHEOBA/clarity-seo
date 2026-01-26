<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions\Contracts;

use App\Models\AutomationWorkflow;

interface ActionInterface
{
    /**
     * Execute the action with the given configuration and context
     *
     * @param array $actionConfig The action configuration from the workflow
     * @param array $contextData The context data (trigger data + additional context)
     * @param AutomationWorkflow $workflow The workflow being executed
     * @return array The result of the action execution
     * @throws \Exception If the action fails
     */
    public function execute(array $actionConfig, array $contextData, AutomationWorkflow $workflow): array;

    /**
     * Validate the action configuration
     *
     * @param array $actionConfig The action configuration to validate
     * @return array Array of validation errors (empty if valid)
     */
    public function validate(array $actionConfig): array;

    /**
     * Get the action's display name
     */
    public function getName(): string;

    /**
     * Get the action's description
     */
    public function getDescription(): string;

    /**
     * Get the configuration schema for this action
     */
    public function getConfigSchema(): array;
}