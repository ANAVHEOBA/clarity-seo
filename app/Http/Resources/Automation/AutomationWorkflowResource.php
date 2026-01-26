<?php

declare(strict_types=1);

namespace App\Http\Resources\Automation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AutomationWorkflowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'priority' => $this->priority,
            
            'trigger' => [
                'type' => $this->trigger_type,
                'config' => $this->trigger_config,
            ],
            
            'conditions' => $this->conditions,
            'actions' => $this->actions,
            
            'ai' => [
                'enabled' => $this->ai_enabled,
                'config' => $this->when($this->ai_enabled, $this->ai_config),
            ],
            
            'stats' => [
                'execution_count' => $this->execution_count,
                'last_executed_at' => $this->last_executed_at?->toISOString(),
                'last_successful_execution_at' => $this->last_successful_execution_at?->toISOString(),
            ],
            
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
            
            'recent_executions' => $this->whenLoaded('executions', function () {
                return $this->executions->map(function ($execution) {
                    return [
                        'id' => $execution->id,
                        'status' => $execution->status,
                        'started_at' => $execution->started_at?->toISOString(),
                        'completed_at' => $execution->completed_at?->toISOString(),
                        'duration' => $execution->duration,
                        'actions_completed' => $execution->actions_completed,
                        'actions_failed' => $execution->actions_failed,
                        'error_message' => $execution->error_message,
                    ];
                });
            }),
            
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}