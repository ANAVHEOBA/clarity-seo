<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreAutomationWorkflowRequest;
use App\Http\Requests\Automation\UpdateAutomationWorkflowRequest;
use App\Http\Resources\Automation\AutomationWorkflowResource;
use App\Models\AutomationWorkflow;
use App\Models\Tenant;
use App\Services\Automation\AutomationService;
use App\Services\Automation\Triggers\TriggerEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AutomationWorkflowController extends Controller
{
    public function __construct(
        protected AutomationService $automationService
    ) {}

    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $filters = $request->only(['trigger_type', 'is_active', 'ai_enabled', 'per_page']);
        
        $workflows = $this->automationService->listForTenant($tenant, $filters);

        return AutomationWorkflowResource::collection($workflows);
    }

    public function store(StoreAutomationWorkflowRequest $request, Tenant $tenant): AutomationWorkflowResource
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $workflow = $this->automationService->create($tenant, $data);

        return new AutomationWorkflowResource($workflow);
    }

    public function show(Tenant $tenant, AutomationWorkflow $workflow): AutomationWorkflowResource
    {
        $this->authorize('view', $workflow);

        return new AutomationWorkflowResource($workflow->load(['createdBy', 'executions' => fn($q) => $q->latest()->limit(5)]));
    }

    public function update(UpdateAutomationWorkflowRequest $request, Tenant $tenant, AutomationWorkflow $workflow): AutomationWorkflowResource
    {
        $this->authorize('update', $workflow);

        $data = $request->validated();
        $workflow = $this->automationService->update($workflow, $data);

        return new AutomationWorkflowResource($workflow);
    }

    public function destroy(Tenant $tenant, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('delete', $workflow);

        $this->automationService->delete($workflow);

        return response()->json(['message' => 'Workflow deleted successfully']);
    }

    public function execute(Request $request, Tenant $tenant, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('execute', $workflow);

        $contextData = $request->input('context_data', []);
        
        try {
            $triggerEvaluator = app(TriggerEvaluator::class);
            $triggerEvaluator->handleManualTrigger(
                $workflow->id,
                $contextData,
                $request->user()->id
            );

            return response()->json([
                'message' => 'Workflow execution started',
                'workflow_id' => $workflow->id,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Workflow execution failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function toggle(Tenant $tenant, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('update', $workflow);

        $workflow->update(['is_active' => !$workflow->is_active]);

        return response()->json([
            'message' => $workflow->is_active ? 'Workflow activated' : 'Workflow deactivated',
            'is_active' => $workflow->is_active,
        ]);
    }

    public function executions(Request $request, Tenant $tenant, AutomationWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $workflow);

        $filters = $request->only(['status', 'from', 'to', 'per_page']);
        $executions = $this->automationService->getExecutionHistory($workflow, $filters);

        return response()->json($executions);
    }

    public function stats(Request $request, Tenant $tenant): JsonResponse
    {
        $filters = $request->only(['from', 'to']);
        $stats = $this->automationService->getStats($tenant, $filters);

        return response()->json($stats);
    }

    public function availableActions(): JsonResponse
    {
        $actions = [
            AutomationWorkflow::ACTION_AI_RESPONSE => [
                'name' => 'AI Response',
                'description' => 'Generate AI-powered responses to reviews',
                'category' => 'review_management',
            ],
            AutomationWorkflow::ACTION_NOTIFICATION => [
                'name' => 'Notification',
                'description' => 'Send email, Slack, or webhook notifications',
                'category' => 'communication',
            ],
            AutomationWorkflow::ACTION_ASSIGN_USER => [
                'name' => 'Assign User',
                'description' => 'Assign reviews to specific users',
                'category' => 'workflow',
            ],
            AutomationWorkflow::ACTION_ADD_TAG => [
                'name' => 'Add Tag',
                'description' => 'Add tags to reviews for categorization',
                'category' => 'organization',
            ],
            AutomationWorkflow::ACTION_UPDATE_LISTING => [
                'name' => 'Update Listing',
                'description' => 'Update location listing data',
                'category' => 'listing_management',
            ],
            AutomationWorkflow::ACTION_GENERATE_REPORT => [
                'name' => 'Generate Report',
                'description' => 'Generate and send reports',
                'category' => 'reporting',
            ],
        ];

        return response()->json($actions);
    }

    public function availableTriggers(): JsonResponse
    {
        $triggers = [
            AutomationWorkflow::TRIGGER_REVIEW_RECEIVED => [
                'name' => 'Review Received',
                'description' => 'Triggered when any new review is received',
                'category' => 'review_events',
            ],
            AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW => [
                'name' => 'Negative Review',
                'description' => 'Triggered when a negative review is received',
                'category' => 'review_events',
            ],
            AutomationWorkflow::TRIGGER_POSITIVE_REVIEW => [
                'name' => 'Positive Review',
                'description' => 'Triggered when a positive review is received',
                'category' => 'review_events',
            ],
            AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE => [
                'name' => 'Negative Sentiment',
                'description' => 'Triggered when negative sentiment is detected',
                'category' => 'sentiment_events',
            ],
            AutomationWorkflow::TRIGGER_LISTING_DISCREPANCY => [
                'name' => 'Listing Discrepancy',
                'description' => 'Triggered when listing data discrepancies are found',
                'category' => 'listing_events',
            ],
            AutomationWorkflow::TRIGGER_SCHEDULED => [
                'name' => 'Scheduled',
                'description' => 'Triggered on a schedule (daily, weekly, monthly)',
                'category' => 'time_based',
            ],
            AutomationWorkflow::TRIGGER_MANUAL => [
                'name' => 'Manual',
                'description' => 'Triggered manually by users',
                'category' => 'user_initiated',
            ],
        ];

        return response()->json($triggers);
    }
}