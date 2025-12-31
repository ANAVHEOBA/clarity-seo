<?php

declare(strict_types=1);

namespace App\Http\Requests\AIResponse;

use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkGenerateAIResponseRequest extends FormRequest
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
            'review_ids' => ['required', 'array', 'max:50'],
            'review_ids.*' => ['integer', 'exists:reviews,id'],
            'tone' => ['sometimes', 'string', Rule::in(AIResponseService::VALID_TONES)],
            'language' => ['sometimes', 'string', 'size:2', Rule::in(AIResponseService::VALID_LANGUAGES)],
            'brand_voice_id' => [
                'sometimes',
                'integer',
                Rule::exists('brand_voices', 'id')->where('tenant_id', $tenantId),
            ],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'review_ids.required' => 'At least one review ID is required.',
            'review_ids.max' => 'You can only generate responses for up to 50 reviews at a time.',
            'review_ids.*.exists' => 'One or more review IDs are invalid.',
        ];
    }
}
