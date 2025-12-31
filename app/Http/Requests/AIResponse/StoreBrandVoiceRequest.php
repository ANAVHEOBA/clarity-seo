<?php

declare(strict_types=1);

namespace App\Http\Requests\AIResponse;

use App\Services\AIResponse\AIResponseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrandVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'tone' => ['required', 'string', Rule::in(AIResponseService::VALID_TONES)],
            'guidelines' => ['required', 'string', 'max:5000'],
            'example_responses' => ['sometimes', 'array', 'max:10'],
            'example_responses.*' => ['string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'A brand voice name is required.',
            'tone.required' => 'A tone is required.',
            'tone.in' => 'The tone must be one of: professional, friendly, apologetic, empathetic.',
            'guidelines.required' => 'Guidelines are required for the brand voice.',
        ];
    }
}
