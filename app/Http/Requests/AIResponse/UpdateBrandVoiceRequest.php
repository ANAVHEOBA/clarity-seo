<?php

declare(strict_types=1);

namespace App\Http\Requests\AIResponse;

use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tone' => ['sometimes', 'string', Rule::in(AIResponseService::VALID_TONES)],
            'guidelines' => ['sometimes', 'string', 'max:5000'],
            'example_responses' => ['sometimes', 'array', 'max:10'],
            'example_responses.*' => ['string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tone.in' => 'The tone must be one of: professional, friendly, apologetic, empathetic.',
        ];
    }
}
