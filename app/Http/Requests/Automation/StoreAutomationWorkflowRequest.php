<?php

declare(strict_types=1);

namespace App\Http\Requests\Automation;

use App\Models\AutomationWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0', 'max:100'],
            
            'trigger_type' => [
                'required',
                'string',
                Rule::in([
                    AutomationWorkflow::TRIGGER_REVIEW_RECEIVED,
                    AutomationWorkflow::TRIGGER_NEGATIVE_REVIEW,
                    AutomationWorkflow::TRIGGER_POSITIVE_REVIEW,
                    AutomationWorkflow::TRIGGER_SENTIMENT_NEGATIVE,
                    AutomationWorkflow::TRIGGER_LISTING_DISCREPANCY,
                    AutomationWorkflow::TRIGGER_SCHEDULED,
                    AutomationWorkflow::TRIGGER_MANUAL,
                ]),
            ],
            'trigger_config' => ['array'],
            'trigger_config.rating_threshold' => ['nullable', 'integer', 'min:1', 'max:5'],
            'trigger_config.sentiment_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'trigger_config.platforms' => ['nullable', 'array'],
            'trigger_config.platforms.*' => ['string'],
            'trigger_config.schedule' => ['nullable', 'string'],
            
            'conditions' => ['array'],
            'conditions.*.field' => ['required_with:conditions', 'string'],
            'conditions.*.operator' => [
                'required_with:conditions',
                'string',
                Rule::in(['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'less_than', 'in', 'not_in']),
            ],
            'conditions.*.value' => ['required_with:conditions'],
            
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => [
                'required',
                'string',
                Rule::in([
                    AutomationWorkflow::ACTION_AI_RESPONSE,
                    AutomationWorkflow::ACTION_NOTIFICATION,
                    AutomationWorkflow::ACTION_ASSIGN_USER,
                    AutomationWorkflow::ACTION_ADD_TAG,
                    AutomationWorkflow::ACTION_UPDATE_LISTING,
                    AutomationWorkflow::ACTION_GENERATE_REPORT,
                ]),
            ],
            'actions.*.config' => ['array'],
            'actions.*.critical' => ['boolean'],
            
            'ai_enabled' => ['boolean'],
            'ai_config' => ['array'],
            'ai_config.safety_level' => ['nullable', 'string', Rule::in(['low', 'medium', 'high'])],
            'ai_config.require_approval' => ['boolean'],
            'ai_config.auto_approval' => ['boolean'],
            'ai_config.auto_approval_confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'ai_config.auto_approval_max_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'ai_config.default_tone' => ['nullable', 'string', Rule::in(['professional', 'friendly', 'apologetic', 'empathetic'])],
            'ai_config.max_length' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'ai_config.brand_voice_id' => ['nullable', 'integer', 'exists:brand_voices,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Workflow name is required',
            'trigger_type.required' => 'Trigger type is required',
            'trigger_type.in' => 'Invalid trigger type',
            'actions.required' => 'At least one action is required',
            'actions.min' => 'At least one action is required',
            'actions.*.type.required' => 'Action type is required',
            'actions.*.type.in' => 'Invalid action type',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'is_active' => $this->input('is_active', true),
            'priority' => $this->input('priority', 0),
            'trigger_config' => $this->input('trigger_config', []),
            'conditions' => $this->input('conditions', []),
            'ai_enabled' => $this->input('ai_enabled', false),
            'ai_config' => $this->input('ai_config', []),
        ]);
    }
}