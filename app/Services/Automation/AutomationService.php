<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationExecution;
use App\Models\AutomationLog;
use App\Models\AutomationWorkflow;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Automation\Actions\ActionExecutor;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomationService
{
    public function __construct(
        protected ActionExecutor $actionExecutor
    ) {}

    public function listForTenant(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = AutomationWorkflow::query()
            ->where('tenant_id', $tenant->id)
            ->with(['createdBy']);

        if (isset($filters['trigger_type'])) {
            $query->where('trigger_type', $filters['trigger_type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['ai_enabled'])) {
            $query->where('ai_enabled', $filters['ai_enabled']);
        }

        $query->orderBy('priority', 'desc')
              ->orderBy('created_at', 'desc');

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    public function create(Tenant $tenant, array $data): AutomationWorkflow
    {
        return DB::transaction(function () use ($tenant, $data) {
            $workflow = AutomationWorkflow::create([
                'tenant_id' => $tenant->id,
                'created_by' => $data['created_by'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'priority' => $data['priority'] ?? 0,
                'trigger_type' => $data['trigger_type'],
                'trigger_config' => $data['trigger_config'] ?? [],
                'conditions' => $data['conditions'] ?? [],
                'actions' => $data['actions'],
                'ai_enabled' => $data['ai_enabled'] ?? false,
                'ai_config' => $data['ai_config'] ?? [],
            ]);

            $this->logWorkflowEvent($workflow, 'info', 'Workflow created', [
                'trigger_type' => $workflow->trigger_type,
                'actions_count' => count($workflow->actions),
            ]);

            return $workflow;
        });
    }

    public function update(AutomationWorkflow $workflow, array $data): AutomationWorkflow
    {
        return DB::transaction(function () use ($workflow, $data) {
            $oldData = $workflow->toArray();
            
            $workflow->update($data);

            $this->logWorkflowEvent($workflow, 'info', 'Workflow updated', [
                'changed_fields' => array_keys(array_diff_assoc($data, $oldData)),
            ]);

            return $workflow->fresh();
        });
    }

    public function delete(AutomationWorkflow $workflow): bool
    {
        return DB::transaction(function () use ($workflow) {
            $this->logWorkflowEvent($workflow, 'info', 'Workflow deleted');
            
            return $workflow->delete();
        });
    }

    public function trigger(string $triggerType, array $triggerData, ?string $triggerSource = null): Collection
    {
        $tenant = $this->resolveTenant($triggerData);
        
        if (!$tenant) {
            Log::warning('Could not resolve tenant for automation trigger', [
                'trigger_type' => $triggerType,
                'trigger_data' => $triggerData,
            ]);
            return collect();
        }

        $workflows = $this->getMatchingWorkflows($tenant, $triggerType, $triggerData);
        $executions = collect();

        foreach ($workflows as $workflow) {
            try {
                $execution = $this->executeWorkflow($workflow, $triggerData, $triggerSource);
                $executions->push($execution);
            } catch (\Exception $e) {
                Log::error('Automation workflow execution failed', [
                    'workflow_id' => $workflow->id,
                    'trigger_type' => $triggerType,
                    'error' => $e->getMessage(),
                ]);

                $this->logWorkflowEvent($workflow, 'error', 'Workflow execution failed', [
                    'error' => $e->getMessage(),
                    'trigger_data' => $triggerData,
                ]);
            }
        }

        return $executions;
    }

    public function executeWorkflow(
        AutomationWorkflow $workflow, 
        array $triggerData, 
        ?string $triggerSource = null
    ): AutomationExecution {
        return DB::transaction(function () use ($workflow, $triggerData, $triggerSource) {
            // Create execution record
            $execution = AutomationExecution::create([
                'workflow_id' => $workflow->id,
                'trigger_data' => $triggerData,
                'trigger_source' => $triggerSource,
                'status' => AutomationExecution::STATUS_PENDING,
                'ai_involved' => $workflow->ai_enabled,
            ]);

            $this->logExecutionEvent($execution, 'info', 'Execution started', [
                'workflow_name' => $workflow->name,
                'trigger_source' => $triggerSource,
            ]);

            try {
                $execution->markAsRunning();
                $workflow->incrementExecutionCount();

                $results = [];
                $contextData = array_merge($triggerData, [
                    'workflow' => $workflow->toArray(),
                    'execution_id' => $execution->id,
                ]);

                // Execute each action
                foreach ($workflow->actions as $index => $actionConfig) {
                    try {
                        $this->logExecutionEvent($execution, 'info', "Executing action {$index}", [
                            'action_type' => $actionConfig['type'] ?? 'unknown',
                            'action_config' => $actionConfig,
                        ]);

                        $actionResult = $this->actionExecutor->execute(
                            $actionConfig,
                            $contextData,
                            $workflow
                        );

                        $results[] = [
                            'action_index' => $index,
                            'action_type' => $actionConfig['type'] ?? 'unknown',
                            'success' => true,
                            'result' => $actionResult,
                        ];

                        $execution->incrementActionsCompleted();

                        $this->logExecutionEvent($execution, 'info', "Action {$index} completed", [
                            'action_type' => $actionConfig['type'] ?? 'unknown',
                            'result' => $actionResult,
                        ]);

                    } catch (\Exception $e) {
                        $results[] = [
                            'action_index' => $index,
                            'action_type' => $actionConfig['type'] ?? 'unknown',
                            'success' => false,
                            'error' => $e->getMessage(),
                        ];

                        $execution->incrementActionsFailed();

                        $this->logExecutionEvent($execution, 'error', "Action {$index} failed", [
                            'action_type' => $actionConfig['type'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ], $actionConfig['type'] ?? null, $index);

                        // Continue with other actions unless it's a critical failure
                        if ($actionConfig['critical'] ?? false) {
                            throw $e;
                        }
                    }
                }

                $execution->markAsCompleted($results);
                $workflow->markSuccessfulExecution();

                $this->logExecutionEvent($execution, 'info', 'Execution completed successfully', [
                    'actions_completed' => $execution->actions_completed,
                    'actions_failed' => $execution->actions_failed,
                    'duration' => $execution->duration,
                ]);

            } catch (\Exception $e) {
                $execution->markAsFailed($e->getMessage());

                $this->logExecutionEvent($execution, 'error', 'Execution failed', [
                    'error' => $e->getMessage(),
                    'actions_completed' => $execution->actions_completed,
                    'actions_failed' => $execution->actions_failed,
                ]);

                throw $e;
            }

            return $execution;
        });
    }

    public function getExecutionHistory(AutomationWorkflow $workflow, array $filters = []): LengthAwarePaginator
    {
        $query = $workflow->executions()->with(['logs']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    public function getStats(Tenant $tenant, array $filters = []): array
    {
        $workflowsQuery = AutomationWorkflow::where('tenant_id', $tenant->id);
        $executionsQuery = AutomationExecution::whereHas('workflow', fn($q) => $q->where('tenant_id', $tenant->id));

        if (isset($filters['from'])) {
            $executionsQuery->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $executionsQuery->whereDate('created_at', '<=', $filters['to']);
        }

        $totalWorkflows = $workflowsQuery->count();
        $activeWorkflows = (clone $workflowsQuery)->where('is_active', true)->count();
        $aiEnabledWorkflows = (clone $workflowsQuery)->where('ai_enabled', true)->count();

        $totalExecutions = $executionsQuery->count();
        $successfulExecutions = (clone $executionsQuery)->where('status', AutomationExecution::STATUS_COMPLETED)->count();
        $failedExecutions = (clone $executionsQuery)->where('status', AutomationExecution::STATUS_FAILED)->count();

        $executionsByStatus = (clone $executionsQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $topTriggers = (clone $workflowsQuery)
            ->select('trigger_type', DB::raw('count(*) as count'))
            ->groupBy('trigger_type')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->pluck('count', 'trigger_type')
            ->toArray();

        return [
            'workflows' => [
                'total' => $totalWorkflows,
                'active' => $activeWorkflows,
                'inactive' => $totalWorkflows - $activeWorkflows,
                'ai_enabled' => $aiEnabledWorkflows,
            ],
            'executions' => [
                'total' => $totalExecutions,
                'successful' => $successfulExecutions,
                'failed' => $failedExecutions,
                'success_rate' => $totalExecutions > 0 ? round($successfulExecutions / $totalExecutions * 100, 1) : 0,
                'by_status' => $executionsByStatus,
            ],
            'top_triggers' => $topTriggers,
        ];
    }

    protected function getMatchingWorkflows(Tenant $tenant, string $triggerType, array $triggerData): Collection
    {
        return AutomationWorkflow::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('trigger_type', $triggerType)
            ->orderBy('priority', 'desc')
            ->get()
            ->filter(function (AutomationWorkflow $workflow) use ($triggerType, $triggerData) {
                return $workflow->matchesTrigger($triggerType, $triggerData) &&
                       $workflow->matchesConditions($triggerData);
            });
    }

    protected function resolveTenant(array $triggerData): ?Tenant
    {
        // Try to resolve tenant from various sources in trigger data
        if (isset($triggerData['tenant_id'])) {
            return Tenant::find($triggerData['tenant_id']);
        }

        if (isset($triggerData['review_id'])) {
            $review = Review::find($triggerData['review_id']);
            return $review?->location?->tenant;
        }

        if (isset($triggerData['location_id'])) {
            $location = \App\Models\Location::find($triggerData['location_id']);
            return $location?->tenant;
        }

        return null;
    }

    protected function logWorkflowEvent(
        AutomationWorkflow $workflow,
        string $level,
        string $message,
        array $context = []
    ): void {
        AutomationLog::create([
            'workflow_id' => $workflow->id,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }

    protected function logExecutionEvent(
        AutomationExecution $execution,
        string $level,
        string $message,
        array $context = [],
        ?string $actionType = null,
        ?int $actionIndex = null
    ): void {
        AutomationLog::create([
            'workflow_id' => $execution->workflow_id,
            'execution_id' => $execution->id,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'action_type' => $actionType,
            'action_index' => $actionIndex,
        ]);
    }
}