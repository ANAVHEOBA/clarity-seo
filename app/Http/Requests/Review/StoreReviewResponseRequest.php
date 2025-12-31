<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
            'ai_generated' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content.required' => 'A response message is required.',
            'content.max' => 'The response cannot exceed 5000 characters.',
        ];
    }
}
