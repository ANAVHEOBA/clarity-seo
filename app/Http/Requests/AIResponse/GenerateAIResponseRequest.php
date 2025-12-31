<?php

declare(strict_types=1);

namespace App\Http\Requests\AIResponse;

use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateAIResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->route('tenant')?->id;

        return [
            'tone' => ['sometimes', 'string', Rule::in(AIResponseService::VALID_TONES)],
            'language' => ['sometimes', 'string', 'size:2', Rule::in(AIResponseService::VALID_LANGUAGES)],
            'brand_voice_id' => [
                'sometimes',
                'integer',
                Rule::exists('brand_voices', 'id')->where('tenant_id', $tenantId),
            ],
            'custom_instructions' => ['sometimes', 'string', 'max:1000'],
            'max_length' => ['sometimes', 'integer', 'min:50', 'max:2000'],
            'use_sentiment_context' => ['sometimes', 'boolean'],
            'include_location_context' => ['sometimes', 'boolean'],
            'auto_detect_language' => ['sometimes', 'boolean'],
            'include_quality_score' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tone.in' => 'The tone must be one of: professional, friendly, apologetic, empathetic.',
            'language.in' => 'The language code is not supported.',
            'brand_voice_id.exists' => 'The selected brand voice does not belong to this tenant.',
            'custom_instructions.max' => 'Custom instructions cannot exceed 1000 characters.',
        ];
    }
}
